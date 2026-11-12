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
 * In-app notifications (DATA_MODEL §8b, FB-16). Writes run in the caller's transaction, only
 * after the action really inserted (or deleted) its row; the unread item of a (recipient, type,
 * target) is merged by the partial unique index `uniq_notification_unread` (no lock needed).
 * Reads apply the §8b filters in SQL: suspended actors left out, items without actor or whose
 * post is no longer visible to the recipient omitted (list and unread count share them).
 */
final readonly class NotificationRepository
{
    public const int UNREAD_CAP = 100;

    /** Event type → table of the target, its owner column, and the notification target column. */
    private const array TARGETS = [
        'track_like' => ['table' => 'track', 'owner' => 'owner_id', 'column' => 'track_id'],
        'playlist_like' => ['table' => 'playlist', 'owner' => 'owner_id', 'column' => 'playlist_id'],
        'post_like' => ['table' => 'post', 'owner' => 'author_id', 'column' => 'post_id'],
        'comment' => ['table' => 'post', 'owner' => 'author_id', 'column' => 'post_id'],
    ];

    public function __construct(private Connection $db)
    {
    }

    /** A follow was inserted: `follow` item of the followed user (no target). */
    public function recordFollow(User $follower, User $followed): void
    {
        if ($follower->getId()->equals($followed->getId())) {
            return;
        }
        $id = $this->db->fetchOne(
            "INSERT INTO notification (id, recipient_id, type, track_id, playlist_id, post_id, read_at, created_at, updated_at)
             VALUES (:id, :r, 'follow', NULL, NULL, NULL, NULL, clock_timestamp(), clock_timestamp())
             ON CONFLICT (recipient_id, type, track_id, playlist_id, post_id) WHERE read_at IS NULL
             DO UPDATE SET updated_at = clock_timestamp() RETURNING id",
            ['id' => Uuid::v7()->toRfc4122(), 'r' => $followed->getId()->toRfc4122()],
        );
        $this->addActor($id, $follower);
    }

    /**
     * A like was inserted, or a comment created: item of the target's owner/author, nothing when
     * the actor is the recipient (own content).
     */
    public function recordOn(NotificationType $type, User $actor, Uuid $targetId): void
    {
        $t = self::target($type);
        $id = $this->db->fetchOne(
            "INSERT INTO notification (id, recipient_id, type, {$t['column']}, read_at, created_at, updated_at)
             SELECT :id, x.{$t['owner']}, :type, x.id, NULL, clock_timestamp(), clock_timestamp()
             FROM {$t['table']} x WHERE x.id = :t AND x.{$t['owner']} <> :a
             ON CONFLICT (recipient_id, type, track_id, playlist_id, post_id) WHERE read_at IS NULL
             DO UPDATE SET updated_at = clock_timestamp() RETURNING id",
            ['id' => Uuid::v7()->toRfc4122(), 'type' => $type->value, 't' => $targetId->toRfc4122(), 'a' => $actor->getId()->toRfc4122()],
        );
        $this->addActor($id, $actor);
    }

    /** An unfollow deleted the follow: the follower leaves every `follow` item of that user. */
    public function retractFollow(User $follower, User $followed): void
    {
        $this->retract("n.type = 'follow' AND n.recipient_id = :r", ['r' => $followed->getId()->toRfc4122()], $follower);
    }

    /** An unlike deleted the like: the actor leaves every item (read or not) of that type and target. */
    public function retractOn(NotificationType $type, User $actor, Uuid $targetId): void
    {
        $t = self::target($type);
        $this->retract("n.type = :type AND n.{$t['column']} = :t", ['type' => $type->value, 't' => $targetId->toRfc4122()], $actor);
    }

    /** DATA_MODEL §13b step 4: items left without any actor (their only actors were deleted). */
    public function deleteOrphans(): int
    {
        return (int) $this->db->executeStatement(
            'DELETE FROM notification n WHERE NOT EXISTS (SELECT 1 FROM notification_actor a WHERE a.notification_id = n.id)',
        );
    }

    /**
     * Page of the recipient's items, keyset (updated_at, id) DESC.
     *
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return list<array{id: string, type: string, read: bool, at: string, track_id: ?string, playlist_id: ?string, post_id: ?string, actor_count: int}> $limit + 1 rows
     */
    public function page(User $recipient, ?array $after, int $limit): array
    {
        $params = TrackAudience::params($recipient) + ['limit' => $limit + 1];
        $where = '';
        if (null !== $after) {
            [$at, $aid] = Keyset::timestampAndId($after);
            $where = 'AND (n.updated_at, n.id) < (:at, :aid)';
            $params['at'] = $at->format('Y-m-d H:i:s.uP');
            $params['aid'] = $aid->toRfc4122();
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT n.id, n.type, (n.read_at IS NOT NULL) AS is_read, n.updated_at AS at, n.track_id, n.playlist_id, n.post_id, ac.cnt
             '.$this->listedFrom()." {$where}
             ORDER BY n.updated_at DESC, n.id DESC LIMIT :limit",
            $params,
        );

        return array_map(static fn (array $r): array => [
            'id' => (string) $r['id'],
            'type' => (string) $r['type'],
            'read' => true === $r['is_read'] || 't' === $r['is_read'] || '1' === (string) $r['is_read'],
            'at' => (string) $r['at'],
            'track_id' => null === $r['track_id'] ? null : (string) $r['track_id'],
            'playlist_id' => null === $r['playlist_id'] ? null : (string) $r['playlist_id'],
            'post_id' => null === $r['post_id'] ? null : (string) $r['post_id'],
            'actor_count' => (int) $r['cnt'],
        ], $rows);
    }

    /** Unread items that page() would list, capped at UNREAD_CAP. */
    public function unreadCount(User $recipient): int
    {
        return (int) $this->db->fetchOne(
            'SELECT count(*) FROM (SELECT 1 '.$this->listedFrom().' AND n.read_at IS NULL LIMIT '.self::UNREAD_CAP.') c',
            TrackAudience::params($recipient),
        );
    }

    /**
     * Up to 3 most recent non-suspended actors of each item, newest first.
     *
     * @param list<string> $notificationIds
     *
     * @return array<string, list<string>> notification id => actor ids
     */
    public function recentActors(array $notificationIds): array
    {
        if ([] === $notificationIds) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT x.notification_id, x.actor_id FROM (
                SELECT a.notification_id, a.actor_id,
                       row_number() OVER (PARTITION BY a.notification_id ORDER BY a.created_at DESC, a.actor_id DESC) AS rn
                FROM notification_actor a JOIN app_user u ON u.id = a.actor_id AND u.suspended_at IS NULL
                WHERE a.notification_id IN (:ids)
             ) x WHERE x.rn <= 3 ORDER BY x.notification_id, x.rn',
            ['ids' => $notificationIds],
            ['ids' => ArrayParameterType::STRING],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['notification_id']][] = (string) $r['actor_id'];
        }

        return $out;
    }

    /**
     * Label and image key of the targets of a page, in one query per target type.
     *
     * @param list<string> $trackIds
     * @param list<string> $postIds
     * @param list<string> $playlistIds
     *
     * @return array{track: array<string, array{label: string, image: ?string}>, post: array<string, array{label: string, image: ?string}>, playlist: array<string, string>}
     */
    public function targetLabels(array $trackIds, array $postIds, array $playlistIds): array
    {
        $out = ['track' => [], 'post' => [], 'playlist' => []];
        if ([] !== $trackIds) {
            foreach ($this->db->fetchAllAssociative('SELECT id, title, cover_key FROM track WHERE id IN (:ids)', ['ids' => $trackIds], ['ids' => ArrayParameterType::STRING]) as $r) {
                $out['track'][(string) $r['id']] = ['label' => (string) $r['title'], 'image' => null === $r['cover_key'] ? null : (string) $r['cover_key']];
            }
        }
        if ([] !== $postIds) {
            $rows = $this->db->fetchAllAssociative(
                "SELECT p.id, p.caption, t.title,
                        CASE WHEN p.visual_type = 'video' THEN p.visual_poster_key WHEN p.visual_type = 'image' THEN p.visual_key END AS visual,
                        t.cover_key
                 FROM post p JOIN track t ON t.id = p.track_id WHERE p.id IN (:ids)",
                ['ids' => $postIds],
                ['ids' => ArrayParameterType::STRING],
            );
            foreach ($rows as $r) {
                $caption = null === $r['caption'] ? '' : trim((string) $r['caption']);
                $image = $r['visual'] ?? $r['cover_key'];
                $out['post'][(string) $r['id']] = [
                    'label' => '' === $caption ? (string) $r['title'] : mb_substr($caption, 0, 80),
                    'image' => null === $image ? null : (string) $image,
                ];
            }
        }
        if ([] !== $playlistIds) {
            foreach ($this->db->fetchAllKeyValue('SELECT id, name FROM playlist WHERE id IN (:ids)', ['ids' => $playlistIds], ['ids' => ArrayParameterType::STRING]) as $id => $name) {
                $out['playlist'][(string) $id] = (string) $name;
            }
        }

        return $out;
    }

    /**
     * `POST /me/notifications/read`: unread items of the recipient whose wire `updatedAt`
     * (second precision, CONVENTIONS §4) is ≤ $upTo.
     */
    public function markRead(User $recipient, \DateTimeImmutable $upTo): int
    {
        return (int) $this->db->executeStatement(
            "UPDATE notification SET read_at = clock_timestamp()
             WHERE recipient_id = :r AND read_at IS NULL AND date_trunc('second', updated_at) <= :upTo",
            ['r' => $recipient->getId()->toRfc4122(), 'upTo' => $upTo->format('Y-m-d H:i:sP')],
        );
    }

    /**
     * FROM … WHERE shared by the list and the unread count (§8b read-time filters). Binds `:v`
     * (recipient) and `:mod`. Track and playlist targets are the recipient's own (always visible
     * to their owner); a post must be visible to its author (DATA_MODEL §9).
     */
    private function listedFrom(): string
    {
        $playable = TrackAudience::postPlayableSql('p', 'pt', 'pu');
        $trackVisible = TrackAudience::visibleSql('pt');

        return "FROM notification n
             JOIN LATERAL (
                SELECT count(*) AS cnt FROM notification_actor a
                JOIN app_user au ON au.id = a.actor_id AND au.suspended_at IS NULL
                WHERE a.notification_id = n.id
             ) ac ON ac.cnt > 0
             LEFT JOIN post p ON p.id = n.post_id
             LEFT JOIN track pt ON pt.id = p.track_id
             LEFT JOIN app_user pu ON pu.id = p.author_id
             WHERE n.recipient_id = :v
               AND (n.post_id IS NULL OR {$playable} OR (p.author_id = :v AND {$trackVisible}))";
    }

    private function addActor(mixed $notificationId, User $actor): void
    {
        if (false === $notificationId || null === $notificationId) {
            return; // own content: nothing written
        }
        $this->db->executeStatement(
            'INSERT INTO notification_actor (notification_id, actor_id, created_at) VALUES (:n, :a, clock_timestamp()) ON CONFLICT DO NOTHING',
            ['n' => (string) $notificationId, 'a' => $actor->getId()->toRfc4122()],
        );
    }

    /** @param array<string, string> $params */
    private function retract(string $match, array $params, User $actor): void
    {
        $params['a'] = $actor->getId()->toRfc4122();
        $ids = $this->db->fetchFirstColumn(
            "DELETE FROM notification_actor na USING notification n
             WHERE na.notification_id = n.id AND na.actor_id = :a AND {$match}
             RETURNING na.notification_id",
            $params,
        );
        if ([] === $ids) {
            return;
        }
        $this->db->executeStatement(
            'DELETE FROM notification n WHERE n.id IN (:ids)
               AND NOT EXISTS (SELECT 1 FROM notification_actor a WHERE a.notification_id = n.id)',
            ['ids' => array_map('strval', $ids)],
            ['ids' => ArrayParameterType::STRING],
        );
    }

    /** @return array{table: string, owner: string, column: string} */
    private static function target(NotificationType $type): array
    {
        return self::TARGETS[$type->value] ?? throw new \LogicException('No target for '.$type->value);
    }
}
