<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\SearchResultsProvider;

/**
 * `SearchResults` schema. This class carries the operation and the `users` section
 * (`type=users`, W-27); the other shapes are returned by the same provider as separate classes so
 * absent sections are omitted, not null: {@see TrackSearchResultsView}, {@see ArtistSearchResultsView},
 * {@see PlaylistSearchResultsView}, {@see HashtagSearchResultsView}, and {@see SearchOverviewView}
 * without `type` (W-41).
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
