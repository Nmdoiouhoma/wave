<?php

declare(strict_types=1);

namespace App\Post;

use App\Enum\VisualType;

/**
 * Post visual type from the file's first bytes, never from the client's name or MIME type
 * (W-30). Image: JPEG, PNG, WebP. Video: ISO-BMFF with an MP4 video brand (audio-only brands
 * such as M4A are refused). Anything else (GIF, MP3, …) → null, i.e. 415. The worker
 * (ProcessPostVisual, W-31) still probes and re-encodes: this is the cheap gate, not the proof.
 */
final class VisualSniffer
{
    private const array VIDEO_BRANDS = ['isom', 'iso2', 'iso4', 'iso5', 'iso6', 'mp41', 'mp42', 'avc1', 'M4V ', 'M4VH', 'M4VP', 'dash'];

    public static function sniff(string $path): ?VisualType
    {
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return null;
        }
        $head = (string) fread($handle, 16);
        fclose($handle);

        if (str_starts_with($head, "\xFF\xD8\xFF")
            || str_starts_with($head, "\x89PNG\r\n\x1A\n")
            || (str_starts_with($head, 'RIFF') && 'WEBP' === substr($head, 8, 4))) {
            return VisualType::Image;
        }
        if ('ftyp' === substr($head, 4, 4) && \in_array(substr($head, 8, 4), self::VIDEO_BRANDS, true)) {
            return VisualType::Video;
        }

        return null;
    }
}
