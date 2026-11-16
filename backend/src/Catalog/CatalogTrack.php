<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * A catalogue track (ADR 014). Read-only: never liked, added to a playlist, posted or reported.
 * `previewUrl` is the provider's short-lived preview URL, returned verbatim and played directly
 * by the browser from the provider's CDN (no Wave URL ever wraps it; null when none).
 */
final readonly class CatalogTrack
{
    public function __construct(
        public string $id,
        public string $title,
        public int $durationSec,
        public ?string $previewUrl,
        public ?string $albumTitle,
        public ?string $coverUrl,
        public string $deezerUrl,
    ) {
    }
}
