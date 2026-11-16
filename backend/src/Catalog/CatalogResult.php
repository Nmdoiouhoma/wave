<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Result of a list query (search or chart). `available: false` with empty `items` on provider
 * failure (ADR 014 §7): the front omits the section, never a 500.
 */
final readonly class CatalogResult
{
    /** @param list<CatalogArtistSummary> $items */
    public function __construct(
        public bool $available,
        public array $items,
        public CatalogAttribution $attribution = new CatalogAttribution(),
    ) {
    }

    public static function unavailable(): self
    {
        return new self(false, []);
    }

    /** @param list<CatalogArtistSummary> $items */
    public static function of(array $items): self
    {
        return new self(true, $items);
    }
}
