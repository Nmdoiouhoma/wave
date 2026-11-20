<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * `SearchResults` schema with only the `playlists` section (`GET /search?type=playlists`, W-41). A
 * separate class so absent sections are omitted rather than null, as {@see TrackSearchResultsView}.
 */
final readonly class PlaylistSearchResultsView
{
    public function __construct(
        public PlaylistPage $playlists,
    ) {
    }
}
