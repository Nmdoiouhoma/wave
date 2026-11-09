<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\SearchResultsProvider;

/**
 * `SearchResults` schema. Phase 2: one section per request — `{"users": UserPage}` here (W-27),
 * `{"tracks": TrackPage}` in {@see TrackSearchResultsView} (W-29a); phase 4 adds the other
 * sections (absent ones are omitted, not null).
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
