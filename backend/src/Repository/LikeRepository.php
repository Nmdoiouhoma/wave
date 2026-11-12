<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Enum\NotificationType;
use App\Pagination\Keyset;
use App\Security\TrackAudience;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Track, playlist and post likes (DATA_MODEL §8): `INSERT … ON CONFLICT DO NOTHING`, counters moved
 * only if a row changed, atomic SQL in the caller's transaction. An inserted like notifies the
 * target's owner, a deleted one withdraws the actor from it (DATA_MODEL §8b).
 */
final readonly class LikeRepository
{
    private const array TARGETS = [
        'track' => ['table' => 'track_like', 'column' => 'track_id', 'counter' => 'track'],
        'playlist' => ['table' => 'playlist_like', 'column' => 'playlist_id', 'counter' => 'playlist'],
        'post' => ['table' => 'post_like', 'column' => 'post_id', 'counter' => 'post'],
    ];

    private const array NOTIFICATIONS = [
        'track' => NotificationType::TrackLike,
        'playlist' => NotificationType::PlaylistLike,
        'post' => NotificationType::PostLike,
    ];

    public function __construct(private Connection $db, private NotificationRepository $notifications)
    {
    }

    /**
     * @param 'track'|'playlist'|'post' $kind
     *
     * @return array{bool, int} [changed, like_count]
     */
    public function like(string $kind, User $user, Uuid $targetId): array
    {
        $t = self::TARGETS[$kind];
        $inserted = $this->db->executeStatement(
            "INSERT INTO {$t['table']} (user_id, {$t['column']}, created_at) VALUES (:u, :t, clock_timestamp()) ON CONFLICT DO NOTHING",
            ['u' => $user->getId()->toRfc4122(), 't' => $targetId->toRfc4122()],
        );
        // Counter first: the target row lock orders concurrent writers before the notification row.
        $count = $this->bump($t['counter'], $targetId, 1 === $inserted ? 1 : 0);
        if (1 === $inserted) {
            $this->notifications->recordOn(self::NOTIFICATIONS[$kind], $user, $targetId);
        }

        return [1 === $inserted, $count];
    }

    /**
     * @param 'track'|'playlist'|'post' $kind
     *
     * @return int like_count after the operation
     */
    public function unlike(string $kind, User $user, Uuid $targetId): int
    {
        $t = self::TARGETS[$kind];
        $deleted = $this->db->executeStatement(
            "DELETE FROM {$t['table']} WHERE user_id = :u AND {$t['column']} = :t",
            ['u' => $user->getId()->toRfc4122(), 't' => $targetId->toRfc4122()],
        );
        $count = $this->bump($t['counter'], $targetId, 1 === $deleted ? -1 : 0);
        if (1 === $deleted) {
            $this->notifications->retractOn(self::NOTIFICATIONS[$kind], $user, $targetId);
        }

        return $count;
    }

    /**
     * Removes the user's own like whatever the target's visibility (W-26 F14): having liked
     * it, the user already knows it exists.
     *
     * @param 'track'|'playlist'|'post' $kind
     *
     * @return int|null like_count after the removal, null when the user had no such like
     */
    public function removeOwn(string $kind, User $user, Uuid $targetId): ?int
    {
        $t = self::TARGETS[$kind];
        $deleted = $this->db->executeStatement(
            "DELETE FROM {$t['table']} WHERE user_id = :u AND {$t['column']} = :t",
            ['u' => $user->getId()->toRfc4122(), 't' => $targetId->toRfc4122()],
        );
        if (1 !== $deleted) {
            return null;
        }
        $count = $this->bump($t['counter'], $targetId, -1);
        $this->notifications->retractOn(self::NOTIFICATIONS[$kind], $user, $targetId);

        return $count;
    }

    /**
     * Which of $ids the viewer likes — one query for a whole page (no N+1).
     *
     * @param 'track'|'playlist'|'post' $kind
     * @param list<Uuid>                $ids
     *
     * @return array<string, true>
     */
    public function likedAmong(string $kind, ?User $viewer, array $ids): array
    {
        if (null === $viewer || [] === $ids) {
            return [];
        }
        $t = self::TARGETS[$kind];
        $liked = $this->db->fetchFirstColumn(
            "SELECT {$t['column']} FROM {$t['table']} WHERE user_id = :u AND {$t['column']} IN (:ids)",
            ['u' => $viewer->getId()->toRfc4122(), 'ids' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids)],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_fill_keys(array_map('strval', $liked), true);
    }

    /**
     * The user's liked tracks still visible to them, newest like first, keyset (created_at, track_id).
     *
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return list<array{id: string, at: string}> $limit + 1 rows
     */
    public function likedTracksPage(User $user, ?array $after, int $limit): array
    {
        [$where, $params] = $this->keyset($after, 'l.track_id');
        $params += ['u' => $user->getId()->toRfc4122(), 'limit' => $limit + 1] + TrackAudience::params($user);

        $visible = TrackAudience::visibleSql('t');
        $rows = $this->db->fetchAllAssociative(
            "SELECT l.track_id AS id, l.created_at AS at FROM track_like l JOIN track t ON t.id = l.track_id
             WHERE l.user_id = :u {$where}
               AND {$visible}
             ORDER BY l.created_at DESC, l.track_id DESC LIMIT :limit",
            $params,
        );

        return array_map(
            static fn (array $r): array => ['id' => (string) $r['id'], 'at' => (string) $r['at']],
            $rows,
        );
    }

    /**
     * The user's liked playlists still visible to them (made private/hidden → omitted).
     *
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return list<array{id: string, at: string}> $limit + 1 rows
     */
    public function likedPlaylistsPage(User $user, ?array $after, int $limit): array
    {
        [$where, $params] = $this->keyset($after, 'l.playlist_id');
        $params += ['u' => $user->getId()->toRfc4122(), 'limit' => $limit + 1] + TrackAudience::params($user);

        $rows = $this->db->fetchAllAssociative(
            "SELECT l.playlist_id AS id, l.created_at AS at FROM playlist_like l JOIN playlist p ON p.id = l.playlist_id
             WHERE l.user_id = :u {$where}
               AND ((p.is_public AND p.moderation = 'visible') OR p.owner_id = :u OR :mod = 1)
             ORDER BY l.created_at DESC, l.playlist_id DESC LIMIT :limit",
            $params,
        );

        return array_map(
            static fn (array $r): array => ['id' => (string) $r['id'], 'at' => (string) $r['at']],
            $rows,
        );
    }

    private function bump(string $table, Uuid $id, int $delta): int
    {
        return (int) $this->db->fetchOne(
            "UPDATE {$table} SET like_count = like_count + :d WHERE id = :id RETURNING like_count",
            ['d' => $delta, 'id' => $id->toRfc4122()],
        );
    }

    /**
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return array{string, array<string, string>}
     */
    private function keyset(?array $after, string $idColumn): array
    {
        if (null === $after) {
            return ['', []];
        }
        [$at, $id] = Keyset::timestampAndId($after);

        return ["AND (l.created_at, {$idColumn}) < (:at, :aid)", ['at' => $at->format('Y-m-d H:i:s.uP'), 'aid' => $id->toRfc4122()]];
    }
}
