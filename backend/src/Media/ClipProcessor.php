<?php

declare(strict_types=1);

namespace App\Media;

use App\Enum\FailureReason;
use App\Media\Exception\MediaRejected;
use App\Media\Exception\MediaToolFailed;
use App\Media\Exception\MediaToolTimedOut;

/**
 * Cuts a post clip out of the processed track (DATA_MODEL §14): same codec as the
 * track (AAC-LC, 44.1 kHz stereo), 300 ms fade in and out, `+faststart`. The track
 * is already loudness-normalized: no second loudnorm, the gain must stay the track's.
 */
final class ClipProcessor
{
    public const FADE_SECONDS = 0.3;
    /** Exact duration the AC asks for (±50 ms); anything further means the source is shorter than its metadata. */
    public const DURATION_TOLERANCE_MS = 50;

    private const TIMEOUT_SECONDS = 60.0;

    public function __construct(private readonly FfmpegRunner $ffmpeg)
    {
    }

    /**
     * @throws MediaRejected   the source cannot yield the requested range (never retried)
     * @throws MediaToolFailed transient ffmpeg failure (retried)
     */
    public function cut(string $sourcePath, string $targetPath, int $startMs, int $durationMs): int
    {
        $duration = self::seconds($durationMs);
        $fadeOutStart = self::seconds(max(0, $durationMs - (int) (self::FADE_SECONDS * 1000)));
        $fade = self::seconds((int) (self::FADE_SECONDS * 1000));

        try {
            $this->ffmpeg->ffmpeg([
                // Input options: the demuxer seeks, then stops reading at the end of the range.
                '-ss', self::seconds($startMs), '-t', $duration,
                ...FfmpegRunner::inputOptions($sourcePath, ['mov', 'mp4', 'm4a']),
                '-map', '0:a:0', '-vn', '-sn', '-dn',
                '-map_metadata', '-1', '-map_chapters', '-1',
                '-af', \sprintf('afade=t=in:st=0:d=%s,afade=t=out:st=%s:d=%s', $fade, $fadeOutStart, $fade),
                '-ar', '44100', '-ac', '2',
                '-c:a', 'aac', '-profile:a', 'aac_low', '-b:a', '160k',
                '-movflags', '+faststart',
                '-f', 'mp4',
                $targetPath,
            ], self::TIMEOUT_SECONDS);
        } catch (MediaToolTimedOut $e) {
            throw new MediaRejected(FailureReason::ProcessingError, 'clip cut: '.$e->getMessage(), $e);
        }

        $out = $this->ffmpeg->probe($targetPath, ['mov', 'mp4', 'm4a']);
        if ([] === $out->audioStreams || 'aac' !== $out->audioStreams[0]['codec'] || null === $out->durationSeconds) {
            throw new MediaToolFailed('unexpected ffmpeg output');
        }
        $actualMs = (int) round($out->durationSeconds * 1000);
        if (abs($actualMs - $durationMs) > self::DURATION_TOLERANCE_MS) {
            throw new MediaRejected(FailureReason::ProcessingError, \sprintf('clip is %d ms, expected %d ms', $actualMs, $durationMs));
        }

        return $actualMs;
    }

    private static function seconds(int $ms): string
    {
        return number_format($ms / 1000, 3, '.', '');
    }
}
