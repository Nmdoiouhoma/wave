<?php

declare(strict_types=1);

namespace App\Post;

use App\Message\PurgeObjects;
use App\Storage\StorageArea;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Post deletion side effects (DATA_MODEL §0 and §9), set-based, in the caller's transaction:
 * lock the posts, `hashtag.post_count − 1` per post_hashtag row, delete the `report` rows that
 * target the posts and their comments (polymorphic, no FK), delete the posts (cascades likes,
 * comments, views, hashtags links, notifications; listen.post_id SET NULL), then dispatch
 * `PurgeObjects` for clip/quarantine (private) and visual/poster (public) keys.
 * Used by `DELETE /posts/{id}`, track deletion and, in phase 5, `DELETE /me` (beforeUserDeletion).
 */
final readonly class PostCleanup
{
    public function __construct(private Connection $db, private MessageBusInterface $bus)
    {
    }

    public function deletePost(Uuid $postId): void
    {
        $this->purge('id = :v', $postId);
    }

    /** Call before removing the track (the FK cascade alone would leave hashtag counts and reports stale). */
    public function beforeTrackDeletion(Uuid $trackId): void
    {
        $this->purge('track_id = :v', $trackId);
        $this->db->executeStatement("DELETE FROM report WHERE target_type = 'track' AND target_id = :t", ['t' => $trackId->toRfc4122()]);
    }

    /** For `DELETE /me` (phase 5): the user's posts and every post on the tracks they own; pair with `ReportRepository::deleteForUserContent`. */
    public function beforeUserDeletion(Uuid $userId): void
    {
        $this->purge('author_id = :v OR track_id IN (SELECT id FROM track WHERE owner_id = :v)', $userId);
    }

    private function purge(string $where, Uuid $value): void
    {
        /** @var list<array{id: string, clip_key: ?string, visual_raw_key: ?string, visual_key: ?string, visual_poster_key: ?string}> $rows */
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, clip_key, visual_raw_key, visual_key, visual_poster_key FROM post WHERE {$where} ORDER BY id FOR UPDATE",
            ['v' => $value->toRfc4122()],
        );
        if ([] === $rows) {
            return;
        }
        $ids = array_column($rows, 'id');
        $params = ['ids' => $ids];
        $types = ['ids' => ArrayParameterType::STRING];

        $this->db->executeStatement(
            'UPDATE hashtag h SET post_count = h.post_count - c.n
             FROM (SELECT hashtag_id, COUNT(*) AS n FROM post_hashtag WHERE post_id IN (:ids) GROUP BY hashtag_id) c
             WHERE h.id = c.hashtag_id',
            $params,
            $types,
        );
        $this->db->executeStatement(
            "DELETE FROM report WHERE (target_type = 'post' AND target_id IN (:ids))
                OR (target_type = 'comment' AND target_id IN (SELECT id FROM comment WHERE post_id IN (:ids)))",
            $params,
            $types,
        );
        $this->db->executeStatement('DELETE FROM post WHERE id IN (:ids)', $params, $types);

        $private = [];
        $public = [];
        foreach ($rows as $row) {
            array_push($private, ...array_filter([$row['clip_key'], $row['visual_raw_key']], static fn (?string $k): bool => null !== $k));
            array_push($public, ...array_filter([$row['visual_key'], $row['visual_poster_key']], static fn (?string $k): bool => null !== $k));
        }
        foreach ([StorageArea::Private->value => $private, StorageArea::Public->value => $public] as $area => $keys) {
            foreach (array_chunk($keys, PurgeObjects::MAX_KEYS) as $chunk) {
                $this->bus->dispatch(new PurgeObjects($area, $chunk));
            }
        }
    }
}
