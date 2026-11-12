<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\NotificationPage;
use App\ApiResource\NotificationTargetView;
use App\ApiResource\NotificationView;
use App\ApiResource\UserSummary;
use App\Mapper\DateFormat;
use App\Mapper\UserMapper;
use App\Pagination\CursorCodec;
use App\Pagination\Keyset;
use App\Pagination\PageRequest;
use App\Repository\NotificationRepository;
use App\Repository\PlaylistRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;
use App\Storage\ObjectStorage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /me/notifications` (FB-16, DATA_MODEL §8b): the caller's items, keyset (updatedAt, id)
 * DESC. Fixed number of queries per page: items, actors, actor users, targets, playlist covers.
 *
 * @implements ProviderInterface<NotificationPage>
 */
final readonly class NotificationListProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private NotificationRepository $notifications,
        private UserRepository $users,
        private PlaylistRepository $playlists,
        private UserMapper $userMapper,
        private ObjectStorage $storage,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): NotificationPage
    {
        $me = $this->currentUser->require();
        $request = $context['request'] ?? null;
        $page = PageRequest::fromRequest($request instanceof Request ? $request : null, $this->codec);
        if (null !== $page->after) {
            Keyset::timestampAndId($page->after); // tampered cursor → 400 before any query
        }

        $rows = $this->notifications->page($me, $page->after, $page->limit);
        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encode(Keyset::fromTimestampAndId(new \DateTimeImmutable($last['at']), Uuid::fromString($last['id'])));
        }

        $actorIds = $this->notifications->recentActors(array_column($rows, 'id'));
        $summaries = [];
        foreach ($this->users->findByIdsOrdered(array_values(array_unique(array_merge([], ...array_values($actorIds))))) as $user) {
            $summaries[$user->getId()->toRfc4122()] = $this->userMapper->toSummary($user);
        }
        $targets = $this->notifications->targetLabels(
            array_values(array_filter(array_column($rows, 'track_id'))),
            array_values(array_unique(array_filter(array_column($rows, 'post_id')))),
            array_values(array_filter(array_column($rows, 'playlist_id'))),
        );
        $playlistIds = array_map(static fn (string $id): Uuid => Uuid::fromString($id), array_keys($targets['playlist']));
        $covers = $this->playlists->coverKeys($playlistIds, $me);

        $items = [];
        foreach ($rows as $row) {
            $actors = array_values(array_filter(array_map(
                static fn (string $id): ?UserSummary => $summaries[$id] ?? null,
                $actorIds[$row['id']] ?? [],
            )));
            $target = $this->target($row, $targets, $covers);
            if ([] === $actors || (null === $target && 'follow' !== $row['type'])) {
                continue; // last actor suspended, or target deleted, between two queries of this page
            }
            $items[] = new NotificationView(
                $row['id'],
                $row['type'],
                $actors,
                max($row['actor_count'], \count($actors)),
                $target,
                $row['read'],
                DateFormat::formatMicros(new \DateTimeImmutable($row['at'])),
            );
        }

        return new NotificationPage($items, $next);
    }

    /**
     * @param array{track_id: ?string, playlist_id: ?string, post_id: ?string}                                                                                              $row
     * @param array{track: array<string, array{label: string, image: ?string}>, post: array<string, array{label: string, image: ?string}>, playlist: array<string, string>} $targets
     * @param array<string, string|null>                                                                                                                                    $covers
     */
    private function target(array $row, array $targets, array $covers): ?NotificationTargetView
    {
        if (null !== $row['track_id'] && isset($targets['track'][$row['track_id']])) {
            $t = $targets['track'][$row['track_id']];

            return new NotificationTargetView('track', $row['track_id'], $t['label'], $this->url($t['image']));
        }
        if (null !== $row['post_id'] && isset($targets['post'][$row['post_id']])) {
            $t = $targets['post'][$row['post_id']];

            return new NotificationTargetView('post', $row['post_id'], $t['label'], $this->url($t['image']));
        }
        if (null !== $row['playlist_id'] && isset($targets['playlist'][$row['playlist_id']])) {
            return new NotificationTargetView('playlist', $row['playlist_id'], $targets['playlist'][$row['playlist_id']], $this->url($covers[$row['playlist_id']] ?? null));
        }

        return null;
    }

    private function url(?string $key): ?string
    {
        return null === $key ? null : $this->storage->publicUrl($key);
    }
}
