<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\SearchResultsProvider;

/**
 * `SearchResults` schema. Phase 2 (W-27): only the `users` section exists, so the payload is
 * `{"users": UserPage}`; phase 4 adds the other sections (absent ones are omitted, not null).
 */
#[ApiResource(
    shortName: 'SearchResults',
    operations: [
        new Get(uriTemplate: '/search', name: 'search', provider: SearchResultsProvider::class),
    ],
)]
final readonly class SearchResultsView
{
    public function __construct(
        public UserPage $users,
    ) {
    }
}
