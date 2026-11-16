<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * ADR 014 §8: shown next to every catalogue block. The text is fixed by the provider's terms.
 */
final readonly class CatalogAttribution
{
    public function __construct(
        public string $text = 'Données et extraits : Deezer',
        public string $url = 'https://www.deezer.com',
    ) {
    }
}
