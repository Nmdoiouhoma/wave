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
 * Reposts (DATA_MODEL §9b, ADR 015): `INSERT … ON CONFLICT DO NOTHING`, `post.repost_count`
 * moved only when a row changed, atomic SQL in the caller's transaction. A new repost notifies
 * the post's author (`repost`, aggregated like `post_like`); an undone one withdraws the actor.
 */
final readonly class RepostRepository
{
    public function __construct(private Connection $db, private NotificationRepository $notifications)
    {
    }

    /** @return array{bool, int} [changed, repost_count] */
    public function repost(User $user, Uuid $postId): array
    {
        $inserted = $this->db->executeStatement(
            'INSERT INTO repost (user_id, post_id, created_at) VALUES (:u, :p, clock_timestamp()) ON CONFLICT DO NOTHING',
            ['u' => $user->getId()->toRfc4122(), 'p' => $postId->toRfc4122()],
        );
        $count = $this->bump($postId, 1 === $inserted ? 1 : 0);
        if (1 === $inserted) {
            $this->notifications->recordOn(NotificationType::Repost, $user, $postId);
        }

        return [1 === $inserted, $count];
    }

    /** @return int repost_count after the operation */
    public function unrepost(User $user, Uuid $postId): int
    {
        $deleted = $this->db->executeStatement(
            'DELETE FROM repost WHERE user_id = :u AND post_id = :p',
            ['u' => $user->getId()->toRfc4122(), 'p' => $postId->toRfc4122()],
        );
        $count = $this->bump($postId, 1 === $deleted ? -1 : 0);
        if (1 === $deleted) {
            $this->notifications->retractOn(NotificationType::Repost, $user, $postId);
        }

        return $count;
    }

    /**
     * Removes the caller's own repost whatever the post's visibility (having reposted it, the
     * caller already knows it exists).
     *
     * @return int|null repost_count after the removal, null when the caller had no such repost
     */
    public function removeOwn(User $user, Uuid $postId): ?int
    {
        $deleted = $this->db->executeStatement(
            'DELETE FROM repost WHERE user_id = :u AND post_id = :p',
            ['u' => $user->getId()->toRfc4122(), 'p' => $postId->toRfc4122()],
        );
        if (1 !== $deleted) {
            return null;
        }
        $count = $this->bump($postId, -1);
        $this->notifications->retractOn(NotificationType::Repost, $user, $postId);

        return $count;
    }

    /**
     * Which of $ids the viewer has reposted — one query for a whole page (no N+1).
     *
     * @param list<Uuid> $ids
     *
     * @return array<string, true>
     */
    public function repostedAmong(?User $viewer, array $ids): array
    {
        if (null === $viewer || [] === $ids) {
            return [];
        }
        $rows = $this->db->fetchFirstColumn(
            'SELECT post_id FROM repost WHERE user_id = :u AND post_id IN (:ids)',
            ['u' => $viewer->getId()->toRfc4122(), 'ids' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids)],
            ['ids' => ArrayParameterType::STRING],
        );

        return array_fill_keys(array_map('strval', $rows), true);
    }

    /**
     * `/users/{username}/reposts`: posts reposted by $author, still publicly playable and playable
     * for $viewer, newest repost first, keyset (created_at, post_id); fetches $limit + 1 rows.
     *
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return list<array{id: string, at: string}>
     */
    public function userRepostsPage(User $author, User $viewer, ?array $after, int $limit): array
    {
        [$where, $params] = $this->keyset($after);
        $params += ['author' => $author->getId()->toRfc4122(), 'v' => $viewer->getId()->toRfc4122(), 'limit' => $limit + 1];
        $playable = TrackAudience::postPlayableSql('p', 't', 'u');

        $rows = $this->db->fetchAllAssociative(
            "SELECT r.post_id AS id, r.created_at AS at
             FROM repost r
             JOIN post p ON p.id = r.post_id
             JOIN track t ON t.id = p.track_id
             JOIN app_user u ON u.id = p.author_id
             WHERE r.user_id = :author {$where}
               AND {$playable}
               AND t.visibility = 'public' AND (t.allow_clips = true OR t.owner_id = p.author_id)
             ORDER BY r.created_at DESC, r.post_id DESC LIMIT :limit",
            $params,
        );

        return array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'at' => (string) $r['at']], $rows);
    }

    /**
     * `/feed/following` (ADR 015): one row per post at its latest activity — authored by a followed
     * user and playable for $viewer, or reposted by a followed (non-suspended) user when the post is
     * publicly playable, playable for $viewer and not $viewer's own. Keyset (activity_at, post_id)
     * DESC; fetches $limit + 1 rows. reposter_id is null when the latest activity is the authorship.
     *
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return list<array{post_id: string, at: string, reposter_id: ?string}>
     */
    public function followingActivityPage(User $viewer, ?array $after, int $limit): array
    {
        $params = ['v' => $viewer->getId()->toRfc4122(), 'limit' => $limit + 1];
        $keyset = '';
        if (null !== $after) {
            [$at, $id] = Keyset::timestampAndId($after);
            $keyset = 'AND (activity_at, post_id) < (:at, :aid)';
            $params += ['at' => $at->format('Y-m-d H:i:s.uP'), 'aid' => $id->toRfc4122()];
        }
        $authored = TrackAudience::postPlayableSql('p', 't', 'u');

        $rows = $this->db->fetchAllAssociative(
            "WITH activity AS (
                SELECT p.id AS post_id, p.created_at AS activity_at, NULL::uuid AS reposter_id
                FROM post p
                JOIN track t ON t.id = p.track_id
                JOIN app_user u ON u.id = p.author_id
                WHERE p.author_id IN (SELECT followed_id FROM follow WHERE follower_id = :v)
                  AND {$authored}
                UNION ALL
                SELECT r.post_id, r.created_at AS activity_at, r.user_id AS reposter_id
                FROM repost r
                JOIN app_user ru ON ru.id = r.user_id
                JOIN post p ON p.id = r.post_id
                JOIN track t ON t.id = p.track_id
                WHERE r.user_id IN (SELECT followed_id FROM follow WHERE follower_id = :v)
                  AND ru.suspended_at IS NULL
                  AND p.author_id <> :v
                  AND p.status = 'ready' AND p.moderation = 'visible' AND t.moderation = 'visible'
                  AND t.visibility = 'public' AND (t.allow_clips = true OR t.owner_id = p.author_id)
            ),
            ranked AS (
                SELECT post_id, activity_at, reposter_id,
                       ROW_NUMBER() OVER (PARTITION BY post_id ORDER BY activity_at DESC, reposter_id NULLS FIRST) AS rn
                FROM activity
            )
            SELECT post_id, activity_at AS at, reposter_id
            FROM ranked
            WHERE rn = 1 {$keyset}
            ORDER BY activity_at DESC, post_id DESC LIMIT :limit",
            $params,
        );

        return array_map(
            static fn (array $r): array => [
                'post_id' => (string) $r['post_id'],
                'at' => (string) $r['at'],
                'reposter_id' => null === $r['reposter_id'] ? null : (string) $r['reposter_id'],
            ],
            $rows,
        );
    }

    /**
     * DATA_MODEL §13b: a reposter's erasure decrements every post they had reposted, then drops the
     * rows (the FK CASCADE would drop the rows without fixing the counter). One call per eraser.
     */
    public function purgeByUser(Uuid $userId): void
    {
        $this->db->executeStatement(
            'UPDATE post SET repost_count = repost_count - 1 WHERE id IN (SELECT post_id FROM repost WHERE user_id = :u)',
            ['u' => $userId->toRfc4122()],
        );
        $this->db->executeStatement('DELETE FROM repost WHERE user_id = :u', ['u' => $userId->toRfc4122()]);
    }

    private function bump(Uuid $postId, int $delta): int
    {
        return (int) $this->db->fetchOne(
            'UPDATE post SET repost_count = repost_count + :d WHERE id = :id RETURNING repost_count',
            ['d' => $delta, 'id' => $postId->toRfc4122()],
        );
    }

    /**
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return array{string, array<string, string>}
     */
    private function keyset(?array $after): array
    {
        if (null === $after) {
            return ['', []];
        }
        [$at, $id] = Keyset::timestampAndId($after);

        return ['AND (r.created_at, r.post_id) < (:at, :aid)', ['at' => $at->format('Y-m-d H:i:s.uP'), 'aid' => $id->toRfc4122()]];
    }
}
