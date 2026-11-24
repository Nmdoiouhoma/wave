<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ArtistPage;
use App\ApiResource\ArtistSearchResultsView;
use App\ApiResource\HashtagPage;
use App\ApiResource\HashtagSearchResultsView;
use App\ApiResource\HashtagView;
use App\ApiResource\PlaylistPage;
use App\ApiResource\PlaylistSearchResultsView;
use App\ApiResource\SearchOverviewView;
use App\ApiResource\SearchResultsView;
use App\ApiResource\TrackPage;
use App\ApiResource\TrackSearchResultsView;
use App\ApiResource\UserPage;
use App\Entity\Artist;
use App\Entity\Track;
use App\Entity\User;
use App\Mapper\ArtistMapper;
use App\Mapper\PlaylistMapper;
use App\Mapper\TrackMapper;
use App\Mapper\UserMapper;
use App\Pagination\CursorCodec;
use App\Pagination\PageRequest;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Problem\Violation;
use App\Repository\ArtistRepository;
use App\Repository\HashtagRepository;
use App\Repository\PlaylistRepository;
use App\Repository\TrackRepository;
use App\Repository\UserRepository;
use App\Search\SearchProvider;
use App\Search\SearchType;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

/**
 * `GET /search`. With `type`: only that section, paginated; the cursor carries the type, the
 * query it belongs to and the offset (signed, ADR 002 exception), at most 200 results reachable.
 * Without `type` (W-41): the five sections with at most 5 items each; a section's `nextCursor`
 * continues it with `type=`; a cursor without `type` is refused. Catalogue artists are not a
 * section (ADR 014 §3).
 *
 * @implements ProviderInterface<object>
 */
final readonly class SearchResultsProvider implements ProviderInterface
{
    public const int OVERVIEW_LIMIT = 5;

    public function __construct(
        private SearchProvider $search,
        private UserRepository $users,
        private UserMapper $mapper,
        private TrackRepository $tracks,
        private TrackMapper $trackMapper,
        private ArtistRepository $artists,
        private ArtistMapper $artistMapper,
        private PlaylistRepository $playlists,
        private PlaylistMapper $playlistMapper,
        private HashtagRepository $hashtags,
        private CurrentUser $currentUser,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object
    {
        $viewer = $this->currentUser->require();
        $request = $context['request'] ?? null;
        $request = $request instanceof Request ? $request : new Request();

        $q = trim($request->query->getString('q'));
        $overview = !$request->query->has('type');
        $type = SearchType::tryFrom($request->query->getString('type'));
        $violations = [];
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            $violations[] = new Violation('q', 'The query must be 2 to 100 characters long.', Length::TOO_SHORT_ERROR);
        }
        if (!$overview && null === $type) {
            $violations[] = new Violation('type', 'Unknown search type.', Choice::NO_SUCH_CHOICE_ERROR);
        }
        if ([] !== $violations) {
            throw new ApiProblemException(ProblemCode::ValidationFailed, 'Validation failed.', $violations);
        }

        $page = PageRequest::fromRequest($request, $this->codec);
        if (null === $type) {
            if (null !== $page->after) {
                throw CursorCodec::invalid();
            }

            return new SearchOverviewView(
                $this->tracks($q, $viewer, 0, self::OVERVIEW_LIMIT),
                $this->artists($q, $viewer, 0, self::OVERVIEW_LIMIT),
                $this->users($q, $viewer, 0, self::OVERVIEW_LIMIT),
                $this->playlists($q, $viewer, 0, self::OVERVIEW_LIMIT),
                $this->hashtags($q, $viewer, 0, self::OVERVIEW_LIMIT),
            );
        }

        $offset = 0;
        if (null !== $page->after) {
            [$kind, $forQuery, $cursorOffset] = $page->after + [null, null, null];
            if ($type->value !== $kind || $forQuery !== mb_strtolower($q) || !\is_int($cursorOffset) || $cursorOffset < 0 || $cursorOffset >= SearchProvider::MAX_RESULTS) {
                throw CursorCodec::invalid();
            }
            $offset = $cursorOffset;
        }

        return match ($type) {
            SearchType::Tracks => new TrackSearchResultsView($this->tracks($q, $viewer, $offset, $page->limit)),
            SearchType::Artists => new ArtistSearchResultsView($this->artists($q, $viewer, $offset, $page->limit)),
            SearchType::Users => new SearchResultsView($this->users($q, $viewer, $offset, $page->limit)),
            SearchType::Playlists => new PlaylistSearchResultsView($this->playlists($q, $viewer, $offset, $page->limit)),
            SearchType::Hashtags => new HashtagSearchResultsView($this->hashtags($q, $viewer, $offset, $page->limit)),
        };
    }

    private function tracks(string $q, User $viewer, int $offset, int $limit): TrackPage
    {
        [$ids, $next] = $this->hits(SearchType::Tracks, $q, $viewer, $offset, $limit);
        $byId = $this->tracks->findByIdsWithRelations($ids);
        $ordered = array_values(array_filter(array_map(static fn (string $id): ?Track => $byId[$id] ?? null, $ids)));

        return new TrackPage($this->trackMapper->toViews($ordered, $viewer), $next);
    }

    private function artists(string $q, User $viewer, int $offset, int $limit): ArtistPage
    {
        [$ids, $next] = $this->hits(SearchType::Artists, $q, $viewer, $offset, $limit);

        return new ArtistPage(
            array_map(fn (Artist $artist) => $this->artistMapper->toView($artist, $viewer), $this->artists->findByIdsOrdered($ids)),
            $next,
        );
    }

    private function users(string $q, User $viewer, int $offset, int $limit): UserPage
    {
        [$ids, $next] = $this->hits(SearchType::Users, $q, $viewer, $offset, $limit);

        return new UserPage($this->mapper->toListItems($this->users->findByIdsOrdered($ids), $viewer), $next);
    }

    private function playlists(string $q, User $viewer, int $offset, int $limit): PlaylistPage
    {
        [$ids, $next] = $this->hits(SearchType::Playlists, $q, $viewer, $offset, $limit);

        return new PlaylistPage($this->playlistMapper->toViews($this->playlists->findByIdsOrdered($ids), $viewer), $next);
    }

    private function hashtags(string $q, User $viewer, int $offset, int $limit): HashtagPage
    {
        $hits = $this->search->search($q, SearchType::Hashtags, $viewer->getId(), $offset, $limit);

        return new HashtagPage(
            array_map(static fn (array $row) => new HashtagView($row['name'], $row['postCount']), $this->hashtags->findVisibleByIdsOrdered($hits->ids, $viewer->getId())),
            $this->next(SearchType::Hashtags, $q, $hits->nextOffset),
        );
    }

    /** @return array{list<string>, ?string} ids in relevance order, next cursor */
    private function hits(SearchType $type, string $q, User $viewer, int $offset, int $limit): array
    {
        $hits = $this->search->search($q, $type, $viewer->getId(), $offset, $limit);

        return [array_map(static fn (Uuid $id): string => $id->toRfc4122(), $hits->ids), $this->next($type, $q, $hits->nextOffset)];
    }

    private function next(SearchType $type, string $q, ?int $nextOffset): ?string
    {
        return null === $nextOffset ? null : $this->codec->encode([$type->value, mb_strtolower($q), $nextOffset]);
    }
}
