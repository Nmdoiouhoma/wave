<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Catalog\CatalogArtistSummary;
use App\Catalog\CatalogAttribution;
use App\State\Provider\CatalogArtistListProvider;

/**
 * `CatalogArtistList` schema (ADR 014): the result of a catalogue search or chart. `available`
 * is false on provider failure (empty `items`, the front omits the section); `attribution` is
 * carried on every response. Flag `CATALOG_PROVIDER=none` → 404 on both routes.
 */
#[ApiResource(
    shortName: 'CatalogArtistList',
    operations: [
        new Get(
            uriTemplate: '/catalog/artists',
            name: 'searchCatalogArtists',
            provider: CatalogArtistListProvider::class,
        ),
        new Get(
            uriTemplate: '/catalog/charts/artists',
            name: 'listCatalogChartArtists',
            provider: CatalogArtistListProvider::class,
        ),
    ],
)]
final readonly class CatalogArtistListView
{
    /** @param list<CatalogArtistSummary> $items */
    public function __construct(
        public bool $available,
        public array $items,
        public CatalogAttribution $attribution,
    ) {
    }
}
