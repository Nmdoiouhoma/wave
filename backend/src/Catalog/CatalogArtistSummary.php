<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * A catalogue artist (ADR 014). `id` is a provider-prefixed opaque string `dz:<digits>`.
 * `name` is plain text from the provider, never rendered as HTML by the front.
 */
final readonly class CatalogArtistSummary
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $pictureUrl,
        public ?int $fanCount,
        public string $deezerUrl,
    ) {
    }
}
