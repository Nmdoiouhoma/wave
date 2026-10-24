<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Output specs of DATA_MODEL §14 (all WebP q80).
 */
enum ImageVariant
{
    /** Track cover: 800x800 center-crop. */
    case Cover;
    /** User / artist avatar: 400x400 center-crop. */
    case Avatar;
    /** Post visual image: fit within 1080x1920, no upscaling, no crop. */
    case PostVisual;

    public function width(): int
    {
        return match ($this) {
            self::Cover => 800,
            self::Avatar => 400,
            self::PostVisual => 1080,
        };
    }

    public function height(): int
    {
        return match ($this) {
            self::Cover => 800,
            self::Avatar => 400,
            self::PostVisual => 1920,
        };
    }

    public function filter(): string
    {
        $w = $this->width();
        $h = $this->height();

        return match ($this) {
            self::Cover, self::Avatar => \sprintf('scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d', $w, $h, $w, $h),
            self::PostVisual => \sprintf("scale='min(%d,iw)':'min(%d,ih)':force_original_aspect_ratio=decrease", $w, $h),
        };
    }
}
