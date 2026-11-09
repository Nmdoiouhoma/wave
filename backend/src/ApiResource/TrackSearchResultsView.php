<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * `SearchResults` schema with only the `tracks` section (`GET /search?type=tracks`, W-29a):
 * `{"tracks": TrackPage}`. Returned by {@see \App\State\Provider\SearchResultsProvider}; a separate
 * class so absent sections are omitted rather than null (skip_null_values stays false globally).
 */
final readonly class TrackSearchResultsView
{
    public function __construct(
        public TrackPage $tracks,
    ) {
    }
}
