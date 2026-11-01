<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlaylistPage;
use App\Mapper\PlaylistMapper;
use App\Pagination\CursorCodec;
use App\Pagination\Keyset;
use App\Pagination\PageRequest;
use App\Repository\LikeRepository;
use App\Repository\PlaylistRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;

/**
 * `/users/{username}/playlists` (newest first; others see public visible ones) and
 * `/me/likes/playlists` (like date desc; no longer visible ones omitted).
 *
 * @implements ProviderInterface<PlaylistPage>
 */
final readonly class PlaylistListProvider implements ProviderInterface
{
    public function __construct(
        private UserLookup $users,
        private PlaylistRepository $playlists,
        private LikeRepository $likes,
        private CurrentUser $currentUser,
        private PlaylistMapper $mapper,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlaylistPage
    {
        $request = $context['request'] ?? null;
        $page = PageRequest::fromRequest($request instanceof Request ? $request : null, $this->codec);

        if ('listMyLikedPlaylists' === $operation->getName()) {
            $viewer = $this->currentUser->require();
            $rows = $this->likes->likedPlaylistsPage($viewer, $page->after, $page->limit);
            $next = null;
            if (\count($rows) > $page->limit) {
                $rows = \array_slice($rows, 0, $page->limit);
                $last = $rows[\count($rows) - 1];
                $next = $this->codec->encode(Keyset::fromTimestampAndId(new \DateTimeImmutable($last['at']), \Symfony\Component\Uid\Uuid::fromString($last['id'])));
            }

            return new PlaylistPage($this->mapper->toViews($this->playlists->findByIdsOrdered(array_column($rows, 'id')), $viewer), $next);
        }

        $viewer = $this->currentUser->get();
        $owner = $this->users->byUsername($uriVariables['username'] ?? null);
        $seesAll = null !== $viewer && ($viewer->getId()->equals($owner->getId()) || $viewer->isModerator());
        $rows = $this->playlists->findPageByOwner($owner, !$seesAll, $page->after, $page->limit);
        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encode(Keyset::fromTimestampAndId($last->getCreatedAt(), $last->getId()));
        }

        return new PlaylistPage($this->mapper->toViews($rows, $viewer), $next);
    }
}
