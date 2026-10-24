<?php

declare(strict_types=1);

namespace App\Media;

use App\Enum\FailureReason;
use App\Media\Exception\MediaRejected;
use App\Media\Exception\MediaToolFailed;

/**
 * Validates an uploaded image (JPEG/PNG/WebP, sniffed by ffprobe, never by
 * the client MIME) and re-encodes it to WebP q80 at the variant's size.
 * Re-encoding drops every metadata block (EXIF, GPS, ICC comments...).
 */
final class ImageProcessor
{
    /** D4: post visual image <= 5 MB; avatars and covers share the limit. */
    public const MAX_SIZE_BYTES = 5 * 1024 * 1024;
    /** Decompression-bomb guard. */
    public const MAX_PIXELS = 50_000_000;
    public const ACCEPTED_CODECS = ['mjpeg', 'png', 'webp'];

    private const TIMEOUT_SECONDS = 60.0;

    public function __construct(private readonly FfmpegRunner $ffmpeg)
    {
    }

    /**
     * @throws MediaRejected
     * @throws MediaToolFailed
     */
    public function toWebp(string $sourcePath, string $targetPath, ImageVariant $variant): void
    {
        $size = @filesize($sourcePath);
        if (false === $size) {
            throw new MediaToolFailed('source file not readable');
        }
        if ($size > self::MAX_SIZE_BYTES) {
            throw new MediaRejected(FailureReason::TooLarge, \sprintf('%d bytes', $size));
        }

        $probe = $this->ffmpeg->probe($sourcePath, FfmpegRunner::IMAGE_DEMUXERS);
        if (1 !== \count($probe->videoStreams) || [] !== $probe->audioStreams) {
            throw new MediaRejected(FailureReason::InvalidMedia, 'not a single still image');
        }
        $stream = $probe->videoStreams[0];
        if (!\in_array($stream['codec'], self::ACCEPTED_CODECS, true)) {
            throw new MediaRejected(FailureReason::UnsupportedCodec, $stream['codec']);
        }
        if ($stream['width'] < 1 || $stream['height'] < 1 || $stream['width'] * $stream['height'] > self::MAX_PIXELS) {
            throw new MediaRejected(FailureReason::InvalidMedia, \sprintf('dimensions %dx%d', $stream['width'], $stream['height']));
        }

        try {
            $this->ffmpeg->ffmpeg([
                ...FfmpegRunner::inputOptions($sourcePath, FfmpegRunner::IMAGE_DEMUXERS),
                '-map', '0:v:0', '-frames:v', '1',
                '-map_metadata', '-1',
                '-vf', $variant->filter(),
                '-c:v', 'libwebp', '-quality', '80', '-compression_level', '4',
                '-f', 'webp',
                $targetPath,
            ], self::TIMEOUT_SECONDS);
        } catch (MediaToolFailed $e) {
            // ffprobe accepted the header but the pixels do not decode.
            throw new MediaRejected(FailureReason::InvalidMedia, 'decode failed: '.$e->getMessage(), $e);
        }
    }
}
