<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\DiscoverHomeProvider;

/** `DiscoverHome` schema (`GET /discover`, FB-32, W-87): six sections, always present. */
#[ApiResource(
    shortName: 'DiscoverHome',
    operations: [
        new Get(uriTemplate: '/discover', name: 'getDiscoverHome', provider: DiscoverHomeProvider::class),
    ],
)]
final readonly class DiscoverHomeView
{
    /**
     * @param list<DiscoverForYouTrackView> $forYouTracks
     * @param list<TrackView>               $newTracks
     * @param list<TrackView>               $trendingTracks
     * @param list<GenreView>               $genres
     * @param list<UserListItem>            $suggestedUsers
     * @param list<HashtagView>             $trendingHashtags
     */
    public function __construct(
        public array $forYouTracks,
        public array $newTracks,
        public array $trendingTracks,
        public array $genres,
        public array $suggestedUsers,
        public array $trendingHashtags,
    ) {
    }
}
