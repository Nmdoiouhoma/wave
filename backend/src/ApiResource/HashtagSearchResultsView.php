<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * `SearchResults` schema with only the `hashtags` section (`GET /search?type=hashtags`, W-41). A
 * separate class so absent sections are omitted rather than null, as {@see TrackSearchResultsView}.
 */
final readonly class HashtagSearchResultsView
{
    public function __construct(
        public HashtagPage $hashtags,
    ) {
    }
}
