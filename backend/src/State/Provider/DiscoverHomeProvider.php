<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\DiscoverForYouTrackView;
use App\ApiResource\DiscoverHomeView;
use App\ApiResource\GenreView;
use App\ApiResource\HashtagView;
use App\ApiResource\TrackView;
use App\Discover\DiscoverQuery;
use App\Entity\Track;
use App\Mapper\TrackMapper;
use App\Mapper\UserMapper;
use App\Repository\HashtagRepository;
use App\Repository\TrackRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /discover` (FB-32, W-87). Ids come from {@see DiscoverQuery} (global parts cached); every
 * track of the four track sections is loaded in one query and mapped in one batch (one `likedByMe`
 * query), and the cached ids are re-checked on load (public-visible, owner not suspended), so a
 * track hidden or a user suspended since the last cache fill never reaches the response.
 *
 * @implements ProviderInterface<DiscoverHomeView>
 */
final readonly class DiscoverHomeProvider implements ProviderInterface
{
    public function __construct(
        private DiscoverQuery $query,
        private CurrentUser $currentUser,
        private TrackRepository $tracks,
        private TrackMapper $trackMapper,
        private UserRepository $users,
        private UserMapper $userMapper,
        private HashtagRepository $hashtags,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DiscoverHomeView
    {
        $viewer = $this->currentUser->require();
        $me = $viewer->getId()->toRfc4122();

        $forYou = $this->query->forYou($me);
        $trending = $this->query->trendingTrackIds();
        $new = $this->newTrackIds($me);

        $ids = array_values(array_unique([...array_column($forYou, 'id'), ...$trending, ...$new]));
        $loaded = array_filter(
            $this->tracks->findByIdsWithRelations($ids),
            static fn (Track $t): bool => $t->isPublicVisible() && !$t->getOwner()->isSuspended(),
        );
        $views = [];
        foreach ($this->trackMapper->toViews(array_values($loaded), $viewer) as $view) {
            $views[$view->id] = $view;
        }

        $forYouViews = [];
        foreach ($forYou as $item) {
            if (isset($views[$item['id']])) {
                $track = $loaded[$item['id']];
                $forYouViews[] = new DiscoverForYouTrackView(
                    $views[$item['id']],
                    $item['reason'],
                    'artist' === $item['reason'] ? $track->getArtist()->getName() : $track->getGenre()->getName(),
                );
            }
        }

        return new DiscoverHomeView(
            $forYouViews,
            self::pick($views, $new),
            self::pick($views, $trending),
            $this->genres($me),
            $this->userMapper->toListItems($this->users->findByIdsOrdered($this->query->suggestedUserIds($me)), $viewer),
            array_map(
                static fn (array $h): HashtagView => new HashtagView($h['name'], $h['postCount']),
                $this->hashtags->findVisibleByIdsOrdered(array_map(Uuid::fromString(...), $this->query->trendingHashtagIds()), $viewer->getId()),
            ),
        );
    }

    /** @return list<string> */
    private function newTrackIds(string $me): array
    {
        $candidates = $this->query->newTrackCandidates();
        $ids = array_column(array_filter($candidates, static fn (array $c): bool => $c['ownerId'] !== $me), 'id');
        if (\count($ids) < DiscoverQuery::NEW_LIMIT && \count($candidates) >= DiscoverQuery::NEW_CACHED) {
            $ids = array_column($this->query->newTracks($me, DiscoverQuery::NEW_LIMIT), 'id');
        }

        return \array_slice($ids, 0, DiscoverQuery::NEW_LIMIT);
    }

    /** @return list<GenreView> the viewer's affinity genres first, then the global ranking */
    private function genres(string $me): array
    {
        $all = [];
        foreach ($this->query->genresByTrackCount() as $g) {
            $all[$g['id']] = $g['name'];
        }
        $out = [];
        foreach ([...$this->query->genreAffinity($me), ...array_keys($all)] as $id) {
            if (isset($all[$id]) && !isset($out[$id])) {
                $out[$id] = new GenreView($id, $all[$id]);
            }
        }

        return \array_slice(array_values($out), 0, DiscoverQuery::GENRES_LIMIT);
    }

    /**
     * @param array<string, TrackView> $views
     * @param list<string>             $ids
     *
     * @return list<TrackView>
     */
    private static function pick(array $views, array $ids): array
    {
        return array_values(array_filter(array_map(static fn (string $id): ?TrackView => $views[$id] ?? null, $ids)));
    }
}
