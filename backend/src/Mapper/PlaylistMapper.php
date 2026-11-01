<?php

declare(strict_types=1);

namespace App\Mapper;

use App\ApiResource\PlaylistView;
use App\Entity\Playlist;
use App\Entity\User;
use App\Repository\LikeRepository;
use App\Repository\PlaylistRepository;
use App\Storage\ObjectStorage;
use Symfony\Component\Uid\Uuid;

/** Playlist → `Playlist` schema; `likedByMe` and `coverUrl` batch-loaded per page (two queries). */
final readonly class PlaylistMapper
{
    public function __construct(
        private UserMapper $users,
        private LikeRepository $likes,
        private PlaylistRepository $playlists,
        private ObjectStorage $storage,
    ) {
    }

    public function toView(Playlist $playlist, ?User $viewer): PlaylistView
    {
        return $this->toViews([$playlist], $viewer)[0];
    }

    /**
     * @param list<Playlist> $playlists
     *
     * @return list<PlaylistView>
     */
    public function toViews(array $playlists, ?User $viewer): array
    {
        $ids = array_map(static fn (Playlist $p): Uuid => $p->getId(), $playlists);
        $liked = $this->likes->likedAmong('playlist', $viewer, $ids);
        $covers = $this->playlists->coverKeys($ids, $viewer);

        return array_map(function (Playlist $p) use ($viewer, $liked, $covers): PlaylistView {
            $id = $p->getId()->toRfc4122();
            $cover = $covers[$id] ?? null;

            return new PlaylistView(
                $id,
                $p->getName(),
                $p->getDescription(),
                $p->isPublic(),
                $this->users->toSummary($p->getOwner()),
                null === $cover ? null : $this->storage->publicUrl($cover),
                $p->getItemCount(),
                $p->getLikeCount(),
                isset($liked[$id]),
                $p->getModeration()->value,
                $p->isOwnedBy($viewer),
                DateFormat::format($p->getCreatedAt()),
                DateFormat::format($p->getUpdatedAt()),
            );
        }, $playlists);
    }
}
