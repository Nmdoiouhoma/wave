<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * The `CATALOG_PROVIDER` flag (ADR 014 §2). `enabled()` drives `Me.features.catalog` and gates
 * every `/catalog/*` route: when disabled the routes answer 404 `not_found`, so the front hides
 * every catalogue surface from `Me.features.catalog`, never by probing.
 */
final readonly class CatalogFeature
{
    public function __construct(private string $provider)
    {
    }

    public function enabled(): bool
    {
        return 'deezer' === $this->provider;
    }
}
