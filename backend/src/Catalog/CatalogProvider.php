<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * External read-only catalogue (ADR 014, CONVENTIONS §7), flag `CATALOG_PROVIDER`.
 * Implementations: {@see DeezerCatalogProvider}, {@see NullCatalogProvider}.
 */
interface CatalogProvider
{
    /** Never throws for provider failures: returns CatalogResult(available: false, items: []). */
    public function searchArtists(string $query, int $limit): CatalogResult;

    public function chartArtists(int $limit): CatalogResult;

    /**
     * @return CatalogArtist|null null = unknown at the provider (→ 404)
     *
     * @throws CatalogUnavailable when the provider is down and nothing is cached (→ 503)
     */
    public function artist(string $externalId): ?CatalogArtist;
}
