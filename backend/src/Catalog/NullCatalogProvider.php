<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Used when `CATALOG_PROVIDER=none`: the catalogue is disabled. Every `/catalog/*` route is
 * gated to 404 by {@see CatalogFeature} before this is reached; it stays a safe no-op so that
 * even a bypassed gate never produces data or an outbound call.
 */
final readonly class NullCatalogProvider implements CatalogProvider
{
    public function searchArtists(string $query, int $limit): CatalogResult
    {
        return CatalogResult::unavailable();
    }

    public function chartArtists(int $limit): CatalogResult
    {
        return CatalogResult::unavailable();
    }

    public function artist(string $externalId): ?CatalogArtist
    {
        return null;
    }
}
