<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * A catalogue artist page (ADR 014): the summary plus up to 10 top tracks and the attribution.
 */
final readonly class CatalogArtist
{
    /** @param list<CatalogTrack> $topTracks */
    public function __construct(
        public CatalogArtistSummary $summary,
        public array $topTracks,
        public CatalogAttribution $attribution = new CatalogAttribution(),
    ) {
    }
}
