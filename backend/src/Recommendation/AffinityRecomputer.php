<?php

declare(strict_types=1);

namespace App\Recommendation;

use App\Feed\ForYouCursor;
use App\Feed\ForYouWeights as W;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** ADR 005 `user_affinity`: 90-day decayed event sums per genre and artist, normalized per kind, top 20. */
final readonly class AffinityRecomputer
{
    public function __construct(private Connection $db, private ClockInterface $clock)
    {
    }

    public function recompute(Uuid $userId): void
    {
        $params = ['u' => $userId->toRfc4122(), 'now' => ForYouCursor::formatTimestamp($this->clock->now())];
        $win = 'CAST(:now AS timestamptz) - make_interval(days => '.W::AFFINITY_WINDOW_D.')';
        $in = static fn (string $col): string => $col.' > '.$win.' AND '.$col.' <= CAST(:now AS timestamptz)';
        $f = static fn (float $x): string => \sprintf('%.3F', $x);

        $sql = 'WITH ev AS ('
            .' SELECT track_id, CASE WHEN source = \'feed\' THEN '.$f(W::AFFINITY_FEED_LISTEN).' ELSE '.$f(W::AFFINITY_PLAYER_LISTEN).' END AS w, created_at AS ts FROM listen WHERE user_id = :u AND '.$in('created_at')
            .' UNION ALL SELECT track_id, '.$f(W::AFFINITY_TRACK_LIKE).', created_at FROM track_like WHERE user_id = :u AND '.$in('created_at')
            .' UNION ALL SELECT p.track_id, '.$f(W::AFFINITY_POST_LIKE).', pl.created_at FROM post_like pl JOIN post p ON p.id = pl.post_id WHERE pl.user_id = :u AND '.$in('pl.created_at')
            .' UNION ALL SELECT p.track_id, '.$f(W::AFFINITY_COMPLETED_VIEW).', pv.last_seen_at FROM post_view pv JOIN post p ON p.id = pv.post_id WHERE pv.user_id = :u AND pv.completed AND '.$in('pv.last_seen_at')
            .'), w AS (SELECT t.genre_id AS genre, CAST(t.artist_id AS text) AS artist,'
            .' ev.w * power(CAST(0.5 AS double precision), CAST(extract(epoch FROM CAST(:now AS timestamptz) - ev.ts) AS double precision) / 86400.0 / '.W::AFFINITY_HALF_LIFE_D.') AS s'
            .' FROM ev JOIN track t ON t.id = ev.track_id)'
            .', agg AS (SELECT \'genre\' AS kind, genre AS key, sum(s) AS s FROM w GROUP BY genre'
            .' UNION ALL SELECT \'artist\', artist, sum(s) FROM w GROUP BY artist)'
            .', ranked AS (SELECT kind, key, s / max(s) OVER (PARTITION BY kind) AS score,'
            .' row_number() OVER (PARTITION BY kind ORDER BY s DESC, key) AS rn FROM agg)'
            .' INSERT INTO user_affinity (user_id, kind, key, score, updated_at)'
            .' SELECT CAST(:u AS uuid), kind, key, score, CAST(:now AS timestamptz) FROM ranked WHERE rn <= '.W::AFFINITY_TOP;

        $this->db->transactional(function () use ($sql, $params): void {
            $this->db->executeStatement('DELETE FROM user_affinity WHERE user_id = :u', ['u' => $params['u']]);
            $this->db->executeStatement($sql, $params);
        });
    }

    /**
     * Users with any signal in the last 30 days (RecomputeAffinityAll).
     *
     * @return list<string>
     */
    public function activeUserIds(): array
    {
        $since = 'now() - interval \'30 days\'';

        /* @var list<string> */
        return $this->db->fetchFirstColumn(
            'SELECT user_id FROM listen WHERE created_at > '.$since
            .' UNION SELECT user_id FROM track_like WHERE created_at > '.$since
            .' UNION SELECT user_id FROM post_like WHERE created_at > '.$since
            .' UNION SELECT user_id FROM post_view WHERE last_seen_at > '.$since,
        );
    }
}
