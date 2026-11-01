<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Pagination\Keyset;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Follow graph (DATA_MODEL §8): idempotent writes, counters moved only when a row was
 * really inserted/deleted, in the caller's transaction.
 */
final readonly class FollowRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** @return int followers_count of $followed after the operation */
    public function follow(User $follower, User $followed): int
    {
        $inserted = $this->db->executeStatement(
            'INSERT INTO follow (follower_id, followed_id, created_at) VALUES (:a, :b, clock_timestamp()) ON CONFLICT DO NOTHING',
            ['a' => $follower->getId()->toRfc4122(), 'b' => $followed->getId()->toRfc4122()],
        );
        if (1 === $inserted) {
            $this->db->executeStatement('UPDATE app_user SET following_count = following_count + 1 WHERE id = :id', ['id' => $follower->getId()->toRfc4122()]);

            return (int) $this->db->fetchOne('UPDATE app_user SET followers_count = followers_count + 1 WHERE id = :id RETURNING followers_count', ['id' => $followed->getId()->toRfc4122()]);
        }

        return $this->followersCount($followed);
    }

    /** @return int followers_count of $followed after the operation */
    public function unfollow(User $follower, User $followed): int
    {
        $deleted = $this->db->executeStatement(
            'DELETE FROM follow WHERE follower_id = :a AND followed_id = :b',
            ['a' => $follower->getId()->toRfc4122(), 'b' => $followed->getId()->toRfc4122()],
        );
        if (1 === $deleted) {
            $this->db->executeStatement('UPDATE app_user SET following_count = following_count - 1 WHERE id = :id', ['id' => $follower->getId()->toRfc4122()]);

            return (int) $this->db->fetchOne('UPDATE app_user SET followers_count = followers_count - 1 WHERE id = :id RETURNING followers_count', ['id' => $followed->getId()->toRfc4122()]);
        }

        return $this->followersCount($followed);
    }

    public function followersCount(User $user): int
    {
        return (int) $this->db->fetchOne('SELECT followers_count FROM app_user WHERE id = :id', ['id' => $user->getId()->toRfc4122()]);
    }

    /**
     * Which of $userIds the viewer follows — one query for a whole page (no N+1).
     *
     * @param list<Uuid> $userIds
     *
     * @return array<string, true> followed user ids (RFC 4122)
     */
    public function followedAmong(?User $viewer, array $userIds): array
    {
        if (null === $viewer || [] === $userIds) {
            return [];
        }
        $ids = $this->db->fetchFirstColumn(
            'SELECT followed_id FROM follow WHERE follower_id = :v AND followed_id IN (:ids)',
            ['v' => $viewer->getId()->toRfc4122(), 'ids' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $userIds)],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_fill_keys(array_map('strval', $ids), true);
    }

    /**
     * Followers (or followed users) of $user, newest follow first, keyset (created_at, other id).
     *
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return list<array{id: string, at: string}> $limit + 1 rows
     */
    public function page(User $user, bool $followers, ?array $after, int $limit): array
    {
        [$self, $other] = $followers ? ['followed_id', 'follower_id'] : ['follower_id', 'followed_id'];
        $params = ['u' => $user->getId()->toRfc4122(), 'limit' => $limit + 1];
        $where = '';
        if (null !== $after) {
            [$at, $id] = Keyset::timestampAndId($after);
            $where = "AND (f.created_at, f.{$other}) < (:at, :aid)";
            $params['at'] = $at->format('Y-m-d H:i:s.uP');
            $params['aid'] = $id->toRfc4122();
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT f.{$other} AS id, f.created_at AS at FROM follow f
             WHERE f.{$self} = :u {$where}
             ORDER BY f.created_at DESC, f.{$other} DESC LIMIT :limit",
            $params,
        );

        return array_map(
            static fn (array $r): array => ['id' => (string) $r['id'], 'at' => (string) $r['at']],
            $rows,
        );
    }
}
