<?php

declare(strict_types=1);

namespace App\ApiResource;

/**
 * `SearchResults` schema of `GET /search` without `type` (W-41): the five sections, each with at
 * most 5 items and a `nextCursor` reusable with `type=`. Catalogue artists are not a section
 * (ADR 014 §3).
 */
final readonly class SearchOverviewView
{
    public function __construct(
        public TrackPage $tracks,
        public ArtistPage $artists,
        public UserPage $users,
        public PlaylistPage $playlists,
        public HashtagPage $hashtags,
    ) {
    }
}
