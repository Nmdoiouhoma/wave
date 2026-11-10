<?php

declare(strict_types=1);

namespace App\Feed;

use App\Pagination\CursorCodec;
use App\Security\TrackAudience;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * "Pour toi" v1 (ADR 011, FB-12): one SQL query per phase on live tables.
 * Phase u = unseen candidates by (score DESC, id DESC); phase s = candidates seen before the
 * session started, least recently seen first. Page assembly caps 2 posts per author across both
 * phases; the cursor points at the last examined row. Replaced by SqlFeedRanker in W-40.
 */
final readonly class SimpleFeedRanker implements FeedRanker
{
    public function __construct(
        private Connection $db,
        private CursorCodec $codec,
        private ClockInterface $clock,
    ) {
    }

    public function forYou(Uuid $viewerId, ?string $cursor, int $limit): RankedPage
    {
        if (null === $cursor) {
            $phase = ForYouCursor::UNSEEN;
            $seed = bin2hex(random_bytes(4));
            $now = $this->clock->now();
            $after = null;
        } else {
            $c = ForYouCursor::decode($this->codec, $cursor);
            [$phase, $seed, $now, $after] = [$c->phase, $c->seed, $c->now, [$c->key, $c->id]];
        }

        $budget = 3 * $limit + 1;
        $kept = [];
        $perAuthor = [];
        $last = null;
        while (true) {
            $rows = $this->fetch($phase, $viewerId, $seed, $now, $after, $budget);
            foreach ($rows as $row) {
                $last = [$phase, $row['k'], $row['id']];
                $perAuthor[$row['author_id']] ??= 0;
                if ($perAuthor[$row['author_id']] < 2) {
                    ++$perAuthor[$row['author_id']];
                    $kept[] = Uuid::fromString($row['id']);
                    if (\count($kept) === $limit) {
                        break 2;
                    }
                }
            }
            if (\count($rows) === $budget) {
                break; // scan budget used: the page stops at the last examined row
            }
            if (ForYouCursor::SEEN === $phase) {
                return new RankedPage($kept, null); // both phases exhausted for this session
            }
            // Phase u ran dry: continue in phase s within the same page (author cap carried over).
            $phase = ForYouCursor::SEEN;
            $after = null;
        }

        /** @var array{'u'|'s', string, string} $last */
        $next = new ForYouCursor($last[0], $seed, $now, $last[1], $last[2]);

        return new RankedPage($kept, $next->encode($this->codec));
    }

    /**
     * Phase u query (EXPLAIN ANALYZE in docs/handoff/w39-backend.md). `k` = score as text.
     *
     * @internal public for the performance test only
     */
    public static function unseenSql(bool $after): string
    {
        $w = static fn (float $x): string => \sprintf('%.6F', $x);
        $score = 'round(CAST('
            .$w(ForYouWeights::FOLLOW).' * (CASE WHEN p.author_id IN (SELECT followed_id FROM follow WHERE follower_id = :v) THEN 1 ELSE 0 END)'
            .' + '.$w(ForYouWeights::GENRE).' * coalesce(gw.w, 0)'
            .' + '.$w(ForYouWeights::POPULAR_RECENT).' * least(1.0, ln(1 + 3 * p.like_count + 4 * p.comment_count + p.view_count) / ln('.(ForYouWeights::POPULARITY_CAP + 1).')) * fr.r'
            .' + '.$w(ForYouWeights::FRESH).' * fr.r'
            .' + '.$w(ForYouWeights::JITTER).' * abs(CAST(hashtext(CAST(:seed AS text) || p.id::text) AS bigint)) / 2147483647.0'
            .' AS numeric), 9)';

        return 'WITH liked AS ('
            .' SELECT tl.track_id FROM track_like tl WHERE tl.user_id = :v AND tl.created_at > CAST(:now AS timestamptz) - make_interval(days => '.ForYouWeights::GENRE_WINDOW_D.') AND tl.created_at <= CAST(:now AS timestamptz)'
            .' UNION ALL'
            .' SELECT lp.track_id FROM post_like pl JOIN post lp ON lp.id = pl.post_id WHERE pl.user_id = :v AND pl.created_at > CAST(:now AS timestamptz) - make_interval(days => '.ForYouWeights::GENRE_WINDOW_D.') AND pl.created_at <= CAST(:now AS timestamptz)'
            .'), genre_count AS (SELECT lt.genre_id, count(*) AS n FROM liked JOIN track lt ON lt.id = liked.track_id GROUP BY lt.genre_id)'
            .', gw AS (SELECT genre_id, CAST(n AS double precision) / max(n) OVER () AS w FROM genre_count)'
            // MATERIALIZED: the score is computed once per candidate, not again in the keyset filter and the sort.
            .', c AS MATERIALIZED (SELECT p.id, p.author_id, '.$score.' AS score'
            .' FROM post p JOIN track t ON t.id = p.track_id JOIN app_user a ON a.id = p.author_id'
            .' LEFT JOIN gw ON gw.genre_id = t.genre_id'
            .' CROSS JOIN LATERAL (SELECT power(CAST(0.5 AS double precision), CAST(extract(epoch FROM CAST(:now AS timestamptz) - p.created_at) AS double precision) / 3600.0 / '.ForYouWeights::HALF_LIFE_H.') AS r) fr'
            .' WHERE '.self::candidateSql()
            .' AND p.id NOT IN (SELECT post_id FROM post_view WHERE user_id = :v))'
            .' SELECT c.id, c.author_id, CAST(c.score AS text) AS k FROM c'
            .($after ? ' WHERE (c.score, c.id) < (CAST(:k AS numeric), CAST(:kid AS uuid))' : '')
            .' ORDER BY c.score DESC, c.id DESC LIMIT :n';
    }

    /**
     * Phase s query. `k` = last_seen_at, µs UTC.
     *
     * @internal public for the performance test only
     */
    public static function seenSql(bool $after): string
    {
        return "SELECT p.id, p.author_id, to_char(pv.last_seen_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"Z\"') AS k"
            .' FROM post_view pv JOIN post p ON p.id = pv.post_id JOIN track t ON t.id = p.track_id JOIN app_user a ON a.id = p.author_id'
            .' WHERE pv.user_id = :v AND pv.last_seen_at < CAST(:now AS timestamptz) AND '.self::candidateSql()
            .($after ? ' AND (pv.last_seen_at, pv.post_id) > (CAST(:k AS timestamptz), CAST(:kid AS uuid))' : '')
            .' ORDER BY pv.last_seen_at ASC, pv.post_id ASC LIMIT :n';
    }

    /**
     * Candidates of both phases: playable for :v (DATA_MODEL §9 with the shared audience
     * fragment of ADR 012 §8), not own, created before the session started.
     */
    private static function candidateSql(): string
    {
        return "p.status = 'ready' AND p.moderation = 'visible' AND t.moderation = 'visible' AND a.suspended_at IS NULL"
            ." AND ((t.visibility = 'public' AND t.allow_clips) OR (p.author_id = t.owner_id AND ".TrackAudience::sql('t').'))'
            .' AND p.author_id <> :v AND p.created_at <= CAST(:now AS timestamptz)';
    }

    /**
     * @param list<string>|null $after
     *
     * @return list<array{id: string, author_id: string, k: string}>
     */
    private function fetch(string $phase, Uuid $viewerId, string $seed, \DateTimeImmutable $now, ?array $after, int $limit): array
    {
        $params = ['v' => $viewerId->toRfc4122(), 'now' => ForYouCursor::formatTimestamp($now), 'n' => $limit];
        if (ForYouCursor::UNSEEN === $phase) {
            $params['seed'] = $seed;
        }
        if (null !== $after) {
            [$params['k'], $params['kid']] = $after;
        }
        $sql = ForYouCursor::UNSEEN === $phase ? self::unseenSql(null !== $after) : self::seenSql(null !== $after);

        /* @var list<array{id: string, author_id: string, k: string}> */
        return array_map(
            static fn (array $r): array => ['id' => (string) $r['id'], 'author_id' => (string) $r['author_id'], 'k' => (string) $r['k']],
            $this->db->fetchAllAssociative($sql, $params, ['n' => ParameterType::INTEGER]),
        );
    }
}
