<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use App\Security\TrackAudience;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * `post_view` upserts (DATA_MODEL §11). One statement per batch: only posts playable for the
 * caller (TrackAudience::postPlayableSql) and not authored by the caller get a row; the others
 * are ignored silently. `watched_ms` clamped to the clip, max kept, `completed` sticky at ≥ 80 %.
 */
final readonly class PostViewRepository
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * @param array<string, int> $views post id => watched ms (already merged by max)
     *
     * @return int number of first views (counter bumps)
     */
    public function record(User $viewer, array $views): int
    {
        if ([] === $views) {
            return 0;
        }
        $values = [];
        $params = ['v' => TrackAudience::params($viewer)['v']];
        $i = 0;
        foreach ($views as $postId => $ms) {
            $values[] = "(CAST(:id{$i} AS uuid), CAST(:ms{$i} AS int))";
            $params['id'.$i] = $postId;
            $params['ms'.$i] = $ms;
            ++$i;
        }
        $first = $this->db->fetchFirstColumn(
            'INSERT INTO post_view (user_id, post_id, watched_ms, completed, first_seen_at, last_seen_at)
             SELECT CAST(:v AS uuid), p.id, LEAST(x.ms, p.clip_duration_ms), LEAST(x.ms, p.clip_duration_ms) * 5 >= p.clip_duration_ms * 4, now(), now()
             FROM (VALUES '.implode(', ', $values).') AS x(id, ms)
             JOIN post p ON p.id = x.id
             JOIN track t ON t.id = p.track_id
             JOIN app_user u ON u.id = p.author_id
             WHERE p.author_id <> CAST(:v AS uuid) AND '.TrackAudience::postPlayableSql('p', 't', 'u').'
             ORDER BY p.id
             ON CONFLICT (user_id, post_id) DO UPDATE SET
                 watched_ms = GREATEST(post_view.watched_ms, excluded.watched_ms),
                 completed = post_view.completed OR excluded.completed,
                 last_seen_at = now()
             RETURNING CASE WHEN xmax = 0 THEN post_id END',
            $params,
        );
        $inserted = array_values(array_filter($first, static fn (mixed $id): bool => null !== $id));
        if ([] === $inserted) {
            return 0;
        }

        // Locks taken in id order (as the upsert above), so concurrent batches cannot deadlock.
        return (int) $this->db->executeStatement(
            'UPDATE post SET view_count = view_count + 1
             FROM (SELECT id FROM post WHERE id IN (:ids) ORDER BY id FOR UPDATE) s WHERE post.id = s.id',
            ['ids' => $inserted],
            ['ids' => ArrayParameterType::STRING],
        );
    }
}
