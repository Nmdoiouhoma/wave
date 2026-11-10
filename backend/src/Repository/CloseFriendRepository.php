<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Pagination\Keyset;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;

/**
 * `close_friend` (DATA_MODEL §8, FB-18): one list per owner, members are followers (composite
 * FK to `follow`, ON DELETE CASCADE). Idempotent SQL writes, no counter.
 */
final readonly class CloseFriendRepository
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * Adds $friend to $owner's list if $friend follows $owner. Returns false when $friend is not
     * a follower, including when a concurrent unfollow wins (the FK fires, SQLSTATE 23503).
     */
    public function add(User $owner, User $friend): bool
    {
        $params = ['o' => $owner->getId()->toRfc4122(), 'f' => $friend->getId()->toRfc4122()];
        try {
            $inserted = $this->db->fetchOne(
                'INSERT INTO close_friend (owner_id, friend_id, created_at)
                 SELECT :o, :f, clock_timestamp()
                 WHERE EXISTS (SELECT 1 FROM follow WHERE follower_id = :f AND followed_id = :o)
                 ON CONFLICT (owner_id, friend_id) DO NOTHING RETURNING 1',
                $params,
            );
        } catch (ForeignKeyConstraintViolationException) {
            return false;
        }
        if (false !== $inserted) {
            return true;
        }

        return false !== $this->db->fetchOne('SELECT 1 FROM close_friend WHERE owner_id = :o AND friend_id = :f', $params);
    }

    public function remove(User $owner, User $friend): void
    {
        $this->db->executeStatement(
            'DELETE FROM close_friend WHERE owner_id = :o AND friend_id = :f',
            ['o' => $owner->getId()->toRfc4122(), 'f' => $friend->getId()->toRfc4122()],
        );
    }

    /**
     * `scope=members`: members by addition date; `scope=followers`: followers by follow date,
     * with the membership flag. Suspended users left out; keyset (date DESC, user id DESC).
     *
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return list<array{id: string, at: string, close: bool}> $limit + 1 rows
     */
    public function page(User $owner, bool $members, ?array $after, int $limit): array
    {
        [$table, $user, $self] = $members ? ['close_friend', 'friend_id', 'owner_id'] : ['follow', 'follower_id', 'followed_id'];
        $params = ['o' => $owner->getId()->toRfc4122(), 'limit' => $limit + 1];
        $where = '';
        if (null !== $after) {
            [$at, $id] = Keyset::timestampAndId($after);
            $where = "AND (x.created_at, x.{$user}) < (:at, :aid)";
            $params['at'] = $at->format('Y-m-d H:i:s.uP');
            $params['aid'] = $id->toRfc4122();
        }
        $close = $members ? 'TRUE' : 'EXISTS (SELECT 1 FROM close_friend cf WHERE cf.owner_id = x.followed_id AND cf.friend_id = x.follower_id)';

        $rows = $this->db->fetchAllAssociative(
            "SELECT x.{$user} AS id, x.created_at AS at, {$close} AS close FROM {$table} x
             JOIN app_user u ON u.id = x.{$user} AND u.suspended_at IS NULL
             WHERE x.{$self} = :o {$where}
             ORDER BY x.created_at DESC, x.{$user} DESC LIMIT :limit",
            $params,
        );

        return array_map(
            static fn (array $r): array => ['id' => (string) $r['id'], 'at' => (string) $r['at'], 'close' => true === $r['close'] || 't' === $r['close'] || '1' === (string) $r['close']],
            $rows,
        );
    }
}
