<?php

declare(strict_types=1);

namespace App\Media;

use App\Enum\FailureReason;
use App\Media\Exception\MediaRejected;
use App\Media\Exception\MediaToolFailed;

/**
 * Validates an uploaded track and transcodes it to the streaming format of
 * DATA_MODEL §14: AAC-LC 160 kb/s, 44.1 kHz stereo, loudnorm I=-14 LUFS
 * TP=-1.5 LRA=11 (two-pass, linear when possible), MP4 with +faststart.
 */
final class TrackAudioProcessor
{
    /** D4: 10 min. */
    public const MAX_DURATION_SECONDS = 600;
    /** D4: 30 MB (DATA_MODEL track.size_bytes). */
    public const MAX_SIZE_BYTES = 31_457_280;
    /** Codecs accepted from the upload (ffprobe codec_name). */
    public const ACCEPTED_CODECS = ['mp3', 'aac'];

    private const LOUDNORM_TARGET = 'I=-14:TP=-1.5:LRA=11';
    private const TIMEOUT_SECONDS = 300.0;
    /** Below this integrated loudness the input is (near) silence: loudnorm cannot be applied. */
    private const SILENCE_LUFS = -70.0;

    public function __construct(private readonly FfmpegRunner $ffmpeg)
    {
    }

    /**
     * @throws MediaRejected   the upload is not acceptable (never retried)
     * @throws MediaToolFailed transient/unknown ffmpeg failure (retried)
     */
    public function validate(string $sourcePath): Probe
    {
        $size = @filesize($sourcePath);
        if (false === $size) {
            throw new MediaToolFailed('source file not readable');
        }
        if ($size > self::MAX_SIZE_BYTES) {
            throw new MediaRejected(FailureReason::TooLarge, \sprintf('%d bytes', $size));
        }

        $probe = $this->ffmpeg->probe($sourcePath, FfmpegRunner::AUDIO_DEMUXERS);

        if ([] === $probe->audioStreams) {
            throw new MediaRejected(FailureReason::InvalidMedia, 'no audio stream');
        }
        // A real video is not a track; cover art embedded in MP3/M4A is fine.
        foreach ($probe->videoStreams as $video) {
            if (!$video['attachedPic']) {
                throw new MediaRejected(FailureReason::InvalidMedia, 'video stream present');
            }
        }
        $codec = $probe->audioStreams[0]['codec'];
        if (!\in_array($codec, self::ACCEPTED_CODECS, true)) {
            throw new MediaRejected(FailureReason::UnsupportedCodec, $codec);
        }
        if (null === $probe->durationSeconds || $probe->durationSeconds <= 0.0) {
            throw new MediaRejected(FailureReason::InvalidMedia, 'unknown duration');
        }
        if ($probe->durationSeconds > self::MAX_DURATION_SECONDS) {
            throw new MediaRejected(FailureReason::TooLong, \sprintf('%.1f s', $probe->durationSeconds));
        }

        return $probe;
    }

    /**
     * Validates $sourcePath and writes the normalized .m4a to $targetPath.
     *
     * @return int duration of the output in milliseconds
     *
     * @throws MediaRejected
     * @throws MediaToolFailed
     */
    public function process(string $sourcePath, string $targetPath): int
    {
        $this->validate($sourcePath);
        $input = FfmpegRunner::inputOptions($sourcePath, FfmpegRunner::AUDIO_DEMUXERS);

        // Pass 1: measure. A decode error here means a corrupt upload.
        try {
            $stderr = $this->ffmpeg->ffmpeg([
                ...$input,
                '-map', '0:a:0', '-vn', '-sn', '-dn',
                '-af', 'loudnorm='.self::LOUDNORM_TARGET.':print_format=json',
                '-f', 'null', '-',
            ], self::TIMEOUT_SECONDS);
        } catch (MediaToolFailed $e) {
            throw new MediaRejected(FailureReason::InvalidMedia, 'decode failed: '.$e->getMessage(), $e);
        }
        $measured = self::parseLoudnormJson($stderr);

        $filters = [];
        if (null !== $measured && $measured['input_i'] > self::SILENCE_LUFS) {
            $filters[] = \sprintf(
                'loudnorm=%s:measured_I=%s:measured_TP=%s:measured_LRA=%s:measured_thresh=%s:offset=%s:linear=true',
                self::LOUDNORM_TARGET,
                self::num($measured['input_i']),
                self::num($measured['input_tp']),
                self::num($measured['input_lra']),
                self::num($measured['input_thresh']),
                self::num($measured['target_offset']),
            );
        }
        // loudnorm resamples to 192 kHz internally: bring it back to 44.1 kHz.
        $filters[] = 'aresample=44100';

        // Pass 2: normalize + encode.
        $this->ffmpeg->ffmpeg([
            ...$input,
            '-map', '0:a:0', '-vn', '-sn', '-dn',
            '-map_metadata', '-1', '-map_chapters', '-1',
            '-af', implode(',', $filters),
            '-ar', '44100', '-ac', '2',
            '-c:a', 'aac', '-profile:a', 'aac_low', '-b:a', '160k',
            '-movflags', '+faststart',
            '-f', 'mp4',
            $targetPath,
        ], self::TIMEOUT_SECONDS);

        // Sanity check on our own output; duration comes from it.
        $out = $this->ffmpeg->probe($targetPath, ['mov', 'mp4', 'm4a']);
        if ([] === $out->audioStreams || 'aac' !== $out->audioStreams[0]['codec'] || null === $out->durationSeconds) {
            throw new MediaToolFailed('unexpected ffmpeg output');
        }

        return (int) round($out->durationSeconds * 1000);
    }

    /**
     * @return array{input_i: float, input_tp: float, input_lra: float, input_thresh: float, target_offset: float}|null
     *                                                                                                                  null when the measure is unusable (e.g. -inf for silence)
     */
    public static function parseLoudnormJson(string $stderr): ?array
    {
        $start = strrpos($stderr, '{');
        $end = strrpos($stderr, '}');
        if (false === $start || false === $end || $end < $start) {
            return null;
        }
        $data = json_decode(substr($stderr, $start, $end - $start + 1), true);
        if (!\is_array($data)) {
            return null;
        }

        $out = [];
        foreach (['input_i', 'input_tp', 'input_lra', 'input_thresh', 'target_offset'] as $key) {
            $value = $data[$key] ?? null;
            if (!\is_string($value) || !is_numeric($value) || !is_finite((float) $value)) {
                return null;
            }
            $out[$key] = (float) $value;
        }

        return $out;
    }

    private static function num(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
