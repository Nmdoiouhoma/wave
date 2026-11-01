<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Pagination\Keyset;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Track and playlist likes (DATA_MODEL §8): `INSERT … ON CONFLICT DO NOTHING`, counters moved
 * only if a row changed, atomic SQL in the caller's transaction.
 */
final readonly class LikeRepository
{
    private const array TARGETS = [
        'track' => ['table' => 'track_like', 'column' => 'track_id', 'counter' => 'track'],
        'playlist' => ['table' => 'playlist_like', 'column' => 'playlist_id', 'counter' => 'playlist'],
    ];

    public function __construct(private Connection $db)
    {
    }

    /**
     * @param 'track'|'playlist' $kind
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

        return [1 === $inserted, $this->bump($t['counter'], $targetId, 1 === $inserted ? 1 : 0)];
    }

    /**
     * @param 'track'|'playlist' $kind
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

        return $this->bump($t['counter'], $targetId, 1 === $deleted ? -1 : 0);
    }

    /**
     * Which of $ids the viewer likes — one query for a whole page (no N+1).
     *
     * @param 'track'|'playlist' $kind
     * @param list<Uuid>         $ids
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
        $params += ['u' => $user->getId()->toRfc4122(), 'limit' => $limit + 1, 'mod' => $user->isModerator() ? 1 : 0];

        $rows = $this->db->fetchAllAssociative(
            "SELECT l.track_id AS id, l.created_at AS at FROM track_like l JOIN track t ON t.id = l.track_id
             WHERE l.user_id = :u {$where}
               AND ((t.status = 'ready' AND t.visibility = 'public' AND t.moderation = 'visible') OR t.owner_id = :u OR :mod = 1)
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
        $params += ['u' => $user->getId()->toRfc4122(), 'limit' => $limit + 1, 'mod' => $user->isModerator() ? 1 : 0];

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
