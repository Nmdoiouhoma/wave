<?php

declare(strict_types=1);

namespace App\Media;

use App\Enum\FailureReason;
use App\Media\Exception\MediaRejected;
use App\Media\Exception\MediaToolFailed;
use App\Media\Exception\MediaToolTimedOut;

/**
 * Post visual (DATA_MODEL §14): image -> WebP fitting 1080x1920 (ImageProcessor), video ->
 * H.264 main <= 720x1280 <= 30 fps CRF 26 without audio, plus a WebP poster of its first frame.
 * Same hardening as the track pipeline (T11/T12): demuxer whitelist, `file:` only, codec
 * whitelist, 50 Mpx cap, read cap on the decoded duration, wall-clock and rlimit timeouts.
 */
final class PostVisualProcessor
{
    /** Mirrors the API limit (CreatePostProcessor::VIDEO_MAX_BYTES). */
    public const MAX_VIDEO_BYTES = 15_728_640;
    public const MAX_VIDEO_SECONDS = 60;
    public const MAX_PIXELS = ImageProcessor::MAX_PIXELS;
    public const MAX_STREAMS = 16;
    public const MAX_FPS = 30.0;
    public const MAX_WIDTH = 720;
    public const MAX_HEIGHT = 1280;
    public const ACCEPTED_VIDEO_CODECS = ['h264', 'hevc', 'mpeg4', 'vp9', 'av1'];

    private const THREADS = 2;
    private const TIMEOUT_SECONDS = 120.0;
    private const POSTER_TIMEOUT_SECONDS = 30.0;
    /** Decoded-duration tolerance (frame rounding), as for tracks. */
    private const DURATION_TOLERANCE_SECONDS = 0.5;

    public function __construct(
        private readonly FfmpegRunner $ffmpeg,
        private readonly ImageProcessor $images,
    ) {
    }

    /**
     * @throws MediaRejected
     * @throws MediaToolFailed
     */
    public function image(string $sourcePath, string $targetPath): void
    {
        $this->images->toWebp($sourcePath, $targetPath, ImageVariant::PostVisual);
    }

    /**
     * @throws MediaRejected
     * @throws MediaToolFailed
     */
    public function video(string $sourcePath, string $targetPath, string $posterPath): void
    {
        $size = @filesize($sourcePath);
        if (false === $size) {
            throw new MediaToolFailed('source file not readable');
        }
        if ($size > self::MAX_VIDEO_BYTES) {
            throw new MediaRejected(FailureReason::TooLarge, \sprintf('%d bytes', $size));
        }

        $probe = $this->ffmpeg->probe($sourcePath, FfmpegRunner::VIDEO_DEMUXERS);
        if (!$probe->isMp4Family() || $probe->streamCount > self::MAX_STREAMS) {
            throw new MediaRejected(FailureReason::InvalidMedia, 'not an MP4/MOV video or too many streams');
        }
        $videos = array_values(array_filter($probe->videoStreams, static fn (array $v): bool => !$v['attachedPic']));
        if ([] === $videos) {
            throw new MediaRejected(FailureReason::InvalidMedia, 'no video stream');
        }
        $video = $videos[0];
        if (!\in_array($video['codec'], self::ACCEPTED_VIDEO_CODECS, true)) {
            throw new MediaRejected(FailureReason::UnsupportedCodec, $video['codec']);
        }
        if ($video['width'] < 1 || $video['height'] < 1 || $video['width'] * $video['height'] > self::MAX_PIXELS) {
            throw new MediaRejected(FailureReason::InvalidMedia, \sprintf('dimensions %dx%d', $video['width'], $video['height']));
        }
        if (null !== $probe->durationSeconds && $probe->durationSeconds > self::MAX_VIDEO_SECONDS + self::DURATION_TOLERANCE_SECONDS) {
            throw new MediaRejected(FailureReason::TooLong, \sprintf('%.1f s', $probe->durationSeconds));
        }

        $fps = null !== $video['fps'] ? min($video['fps'], self::MAX_FPS) : self::MAX_FPS;
        $filters = [
            // Fit, never upscale; even sides for yuv420p.
            \sprintf("scale='min(%d,iw)':'min(%d,ih)':force_original_aspect_ratio=decrease", self::MAX_WIDTH, self::MAX_HEIGHT),
            'scale=trunc(iw/2)*2:trunc(ih/2)*2',
            // Constant frame rate <= 30 whatever the container claims.
            'fps='.number_format($fps, 3, '.', ''),
            'format=yuv420p',
        ];

        try {
            $run = $this->ffmpeg->run([
                // Headers lie: stop reading one second past the limit and measure what was really decoded.
                '-t', (string) (self::MAX_VIDEO_SECONDS + 1),
                // Few threads: each costs address space, and the worker runs under a prlimit (--as).
                '-threads', (string) self::THREADS,
                ...FfmpegRunner::inputOptions($sourcePath, FfmpegRunner::VIDEO_DEMUXERS),
                '-map', '0:v:0', '-an', '-sn', '-dn',
                '-map_metadata', '-1', '-map_chapters', '-1',
                '-vf', implode(',', $filters),
                '-filter_threads', '1', '-c:v', 'libx264', '-threads', (string) self::THREADS, '-profile:v', 'main', '-preset', 'veryfast', '-crf', '26',
                '-movflags', '+faststart',
                '-f', 'mp4',
                $targetPath,
            ], self::TIMEOUT_SECONDS);
        } catch (MediaToolTimedOut $e) {
            throw new MediaRejected(FailureReason::ProcessingError, 'video encode: '.$e->getMessage(), $e);
        } catch (MediaToolFailed $e) {
            throw new MediaRejected(FailureReason::InvalidMedia, 'decode failed: '.$e->getMessage(), $e);
        }
        if (null !== $run['outTimeSeconds'] && $run['outTimeSeconds'] > self::MAX_VIDEO_SECONDS + self::DURATION_TOLERANCE_SECONDS) {
            throw new MediaRejected(FailureReason::TooLong, \sprintf('decoded %.1f s (declared duration was wrong)', $run['outTimeSeconds']));
        }

        // Our own output: must be a single H.264 stream, no audio.
        $out = $this->ffmpeg->probe($targetPath, FfmpegRunner::VIDEO_DEMUXERS);
        if ([] !== $out->audioStreams || 1 !== \count($out->videoStreams) || 'h264' !== $out->videoStreams[0]['codec']) {
            throw new MediaToolFailed('unexpected ffmpeg video output');
        }

        $this->poster($targetPath, $posterPath);
    }

    private function poster(string $videoPath, string $posterPath): void
    {
        try {
            $this->ffmpeg->ffmpeg([
                ...FfmpegRunner::inputOptions($videoPath, FfmpegRunner::VIDEO_DEMUXERS),
                '-map', '0:v:0', '-frames:v', '1',
                '-map_metadata', '-1',
                '-c:v', 'libwebp', '-quality', '80', '-compression_level', '4',
                '-f', 'webp',
                $posterPath,
            ], self::POSTER_TIMEOUT_SECONDS);
        } catch (MediaToolTimedOut $e) {
            throw new MediaRejected(FailureReason::ProcessingError, 'poster: '.$e->getMessage(), $e);
        }
    }
}
