<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * `SearchResults` schema with only the `artists` section (`GET /search?type=artists`, W-41). A
 * separate class so absent sections are omitted rather than null, as {@see TrackSearchResultsView}.
 */
final readonly class ArtistSearchResultsView
{
    public function __construct(
        public ArtistPage $artists,
    ) {
    }
}
