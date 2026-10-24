<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TrackPage;
use App\Artist\ArtistLookup;
use App\Entity\Track;
use App\Mapper\TrackMapper;
use App\Pagination\CursorCodec;
use App\Pagination\Keyset;
use App\Pagination\PageRequest;
use App\Repository\TrackRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;

/**
 * `/users/{username}/tracks` and `/artists/{slug}/tracks`: newest first, keyset on
 * (created_at, id). Others see public-visible tracks only; owner and moderators see all.
 *
 * @implements ProviderInterface<TrackPage>
 */
final readonly class TrackListProvider implements ProviderInterface
{
    public function __construct(
        private UserLookup $users,
        private ArtistLookup $artists,
        private TrackRepository $tracks,
        private CurrentUser $currentUser,
        private TrackMapper $mapper,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TrackPage
    {
        $viewer = $this->currentUser->get();
        $request = $context['request'] ?? null;
        $page = PageRequest::fromRequest($request instanceof Request ? $request : null, $this->codec);

        if ('listArtistTracks' === $operation->getName()) {
            $artist = $this->artists->visible($uriVariables['slug'] ?? null);
            $seesAll = null !== $viewer && ($artist->isOwnedBy($viewer) || $viewer->isModerator());
            $rows = $this->tracks->findPageByArtist($artist, !$seesAll, $page->after, $page->limit);
        } else {
            $owner = $this->users->byUsername($uriVariables['username'] ?? null);
            $seesAll = null !== $viewer && ($viewer->getId()->equals($owner->getId()) || $viewer->isModerator());
            $rows = $this->tracks->findPageByOwner($owner, !$seesAll, $page->after, $page->limit);
        }

        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encode(Keyset::fromTimestampAndId($last->getCreatedAt(), $last->getId()));
        }

        /* @var list<Track> $rows */
        return new TrackPage($this->mapper->toViews($rows, $viewer), $next);
    }
}
