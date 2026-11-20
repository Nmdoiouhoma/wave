<?php

declare(strict_types=1);

namespace App\Recommendation;

use App\Feed\ForYouCursor;
use App\Feed\ForYouWeights as W;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/** ADR 005 `trending_score` of tracks (7 d, half-life 48 h) and posts (3 d, half-life 24 h). */
final readonly class TrendingRecomputer
{
    public function __construct(private Connection $db, private ClockInterface $clock)
    {
    }

    public function recompute(): void
    {
        $p = ['now' => ForYouCursor::formatTimestamp($this->clock->now())];
        $f = static fn (float $x): string => \sprintf('%.3F', $x);
        $decay = static fn (string $ts, int $halfLifeH): string => 'power(CAST(0.5 AS double precision), CAST(extract(epoch FROM CAST(:now AS timestamptz) - '.$ts.') AS double precision) / 3600.0 / '.$halfLifeH.')';
        $in = static fn (string $col, int $days): string => $col.' > CAST(:now AS timestamptz) - make_interval(days => '.$days.') AND '.$col.' <= CAST(:now AS timestamptz)';

        $td = W::TRACK_TRENDING_WINDOW_D;
        $trackEvents = 'SELECT track_id AS id, '.$f(W::TRACK_LISTEN).' * '.$decay('created_at', W::TRACK_TRENDING_HALF_LIFE_H).' AS v FROM listen WHERE '.$in('created_at', $td)
            .' UNION ALL SELECT track_id, '.$f(W::TRACK_LIKE).' * '.$decay('created_at', W::TRACK_TRENDING_HALF_LIFE_H).' FROM track_like WHERE '.$in('created_at', $td);

        $pd = W::POST_TRENDING_WINDOW_D;
        $h = W::POST_TRENDING_HALF_LIFE_H;
        $postEvents = 'SELECT post_id AS id, '.$f(W::POST_VIEW).' * '.$decay('first_seen_at', $h).' AS v FROM post_view WHERE '.$in('first_seen_at', $pd)
            .' UNION ALL SELECT post_id, '.$f(W::POST_COMPLETED_VIEW).' * '.$decay('last_seen_at', $h).' FROM post_view WHERE completed AND '.$in('last_seen_at', $pd)
            .' UNION ALL SELECT post_id, '.$f(W::POST_LIKE).' * '.$decay('created_at', $h).' FROM post_like WHERE '.$in('created_at', $pd)
            .' UNION ALL SELECT rp.post_id, '.$f(W::REPOST_TRENDING).' * '.$decay('rp.created_at', $h).' FROM repost rp'
            .' JOIN app_user ru ON ru.id = rp.user_id AND ru.suspended_at IS NULL JOIN post rpp ON rpp.id = rp.post_id'
            ." JOIN track rpt ON rpt.id = rpp.track_id AND rpt.visibility = 'public' AND (rpt.allow_clips OR rpp.author_id = rpt.owner_id)"
            ." WHERE rpp.moderation = 'visible' AND ".$in('rp.created_at', $pd)
            .' UNION ALL SELECT post_id, '.$f(W::POST_COMMENT).' * '.$decay('created_at', $h)." FROM comment WHERE moderation = 'visible' AND ".$in('created_at', $pd);

        foreach (['track' => $trackEvents, 'post' => $postEvents] as $table => $events) {
            // Rows with a signal get their sum; rows that had a score and no longer have a signal get 0.
            $this->db->executeStatement(
                'WITH x AS (SELECT id, sum(v) AS v FROM ('.$events.') e GROUP BY id)'
                ." UPDATE $table t SET trending_score = coalesce(x.v, 0)"
                ." FROM (SELECT id FROM $table WHERE trending_score <> 0 UNION SELECT id FROM x) ids LEFT JOIN x ON x.id = ids.id"
                .' WHERE t.id = ids.id',
                $p,
            );
        }
    }
}
