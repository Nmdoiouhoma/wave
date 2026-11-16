<?php

declare(strict_types=1);

namespace App\Catalog;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Picks the {@see CatalogProvider} implementation from the `CATALOG_PROVIDER` flag (ADR 014 §2).
 */
final readonly class CatalogProviderFactory
{
    public function __construct(
        #[Autowire('%env(CATALOG_PROVIDER)%')]
        private string $provider,
        private DeezerCatalogProvider $deezer,
        private NullCatalogProvider $null,
    ) {
    }

    public function create(): CatalogProvider
    {
        return 'deezer' === $this->provider ? $this->deezer : $this->null;
    }
}
