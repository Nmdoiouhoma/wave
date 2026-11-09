<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\SearchResultsView;
use App\ApiResource\TrackPage;
use App\ApiResource\TrackSearchResultsView;
use App\ApiResource\UserPage;
use App\Entity\Track;
use App\Mapper\TrackMapper;
use App\Mapper\UserMapper;
use App\Pagination\CursorCodec;
use App\Pagination\PageRequest;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Problem\Violation;
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
 * `GET /search` — phase 2: `type=users` (W-27) or `type=tracks` (W-29a) required; only that
 * section is returned. The cursor carries the type, the query it belongs to and the offset
 * (signed, ADR 002 exception); at most 200 results are reachable.
 *
 * @implements ProviderInterface<SearchResultsView|TrackSearchResultsView>
 */
final readonly class SearchResultsProvider implements ProviderInterface
{
    public function __construct(
        private SearchProvider $search,
        private UserRepository $users,
        private UserMapper $mapper,
        private TrackRepository $tracks,
        private TrackMapper $trackMapper,
        private CurrentUser $currentUser,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SearchResultsView|TrackSearchResultsView
    {
        $viewer = $this->currentUser->require();
        $request = $context['request'] ?? null;
        $request = $request instanceof Request ? $request : new Request();

        $q = trim($request->query->getString('q'));
        $type = SearchType::tryFrom($request->query->getString('type'));
        $violations = [];
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            $violations[] = new Violation('q', 'The query must be 2 to 100 characters long.', Length::TOO_SHORT_ERROR);
        }
        if (SearchType::Users !== $type && SearchType::Tracks !== $type) {
            $violations[] = new Violation('type', 'Only type=users and type=tracks are available for now.', Choice::NO_SUCH_CHOICE_ERROR);
        }
        if ([] !== $violations) {
            throw new ApiProblemException(ProblemCode::ValidationFailed, 'Validation failed.', $violations);
        }

        $page = PageRequest::fromRequest($request, $this->codec);
        $offset = 0;
        if (null !== $page->after) {
            [$kind, $forQuery, $cursorOffset] = $page->after + [null, null, null];
            if ($type->value !== $kind || $forQuery !== mb_strtolower($q) || !\is_int($cursorOffset) || $cursorOffset < 0 || $cursorOffset >= SearchProvider::MAX_RESULTS) {
                throw CursorCodec::invalid();
            }
            $offset = $cursorOffset;
        }

        $hits = $this->search->search($q, $type, $viewer->getId(), $offset, $page->limit);
        $ids = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $hits->ids);
        $next = null === $hits->nextOffset ? null : $this->codec->encode([$type->value, mb_strtolower($q), $hits->nextOffset]);

        if (SearchType::Tracks === $type) {
            $byId = $this->tracks->findByIdsWithRelations($ids);
            $ordered = array_values(array_filter(array_map(static fn (string $id): ?Track => $byId[$id] ?? null, $ids)));

            return new TrackSearchResultsView(new TrackPage($this->trackMapper->toViews($ordered, $viewer), $next));
        }

        return new SearchResultsView(new UserPage($this->mapper->toListItems($this->users->findByIdsOrdered($ids), $viewer), $next));
    }
}
