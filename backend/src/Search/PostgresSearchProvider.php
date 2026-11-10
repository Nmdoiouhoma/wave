<?php

declare(strict_types=1);

namespace App\Search;

use App\Security\TrackAudience;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * v1 search on PostgreSQL (unaccent + pg_trgm, `%` operator = similarity ≥ 0.3, GIN trigram
 * indexes of DATA_MODEL §2/§5/§6). Phase 2:
 * - users (W-27): username or display name starting with the query (also per word of the display
 *   name) or similar. Ranking: exact username, prefix, similarity, followers, id. Suspended users
 *   and the caller are excluded.
 * - tracks (W-29a, FB-15): only what the caller can see and play — ready, visible, caller in the audience
 *   (TrackAudience, FB-18),
 *   owner not suspended. Title or artist name starting with the query (also per word) or similar.
 * Ranking: own, liked by the caller, exact title, prefix, similarity, play count, id.
 */
final readonly class PostgresSearchProvider implements SearchProvider
{
    public function __construct(private Connection $db)
    {
    }

    public function search(string $query, SearchType $type, Uuid $viewerId, int $offset, int $limit): SearchHits
    {
        $sql = match ($type) {
            SearchType::Users => self::USERS_SQL,
            SearchType::Tracks => str_replace('{AUDIENCE}', TrackAudience::sql('t'), self::TRACKS_SQL),
            default => throw new \LogicException('Only user and track search exist before phase 4.'),
        };
        $limit = max(0, min($limit, self::MAX_RESULTS - $offset));
        if (0 === $limit) {
            return new SearchHits([], null);
        }

        $ids = $this->db->fetchFirstColumn(
            self::QUERY_CTE.$sql,
            ['q' => trim($query), 'viewer' => $viewerId->toRfc4122(), 'offset' => $offset, 'limit' => $limit + 1]
                + (SearchType::Tracks === $type ? ['v' => $viewerId->toRfc4122()] : []),
        );

        $hasMore = \count($ids) > $limit && $offset + $limit < self::MAX_RESULTS;

        return new SearchHits(
            array_map(static fn (mixed $id): Uuid => Uuid::fromString((string) $id), \array_slice($ids, 0, $limit)),
            $hasMore ? $offset + $limit : null,
        );
    }

    /** `q` = normalised query, `p` = the same escaped for LIKE. */
    private const string QUERY_CTE = <<<'SQL'
        WITH n AS (SELECT wave_unaccent(lower(:q)) AS q),
        -- LIKE escaping (chr(92) = backslash) after unaccent, which folds ％ ＿ ﹪ into % _ (F10)
        q AS (SELECT n.q, replace(replace(replace(n.q, chr(92), chr(92) || chr(92)), '%', chr(92) || '%'), '_', chr(92) || '_') AS p FROM n)

        SQL;

    private const string TRACKS_SQL = <<<'SQL'
        , c AS (
            SELECT t.id, t.owner_id, t.play_count,
                   wave_unaccent(lower(t.title)) AS title, wave_unaccent(lower(a.name)) AS artist
            FROM track t
            JOIN artist a ON a.id = t.artist_id
            JOIN app_user o ON o.id = t.owner_id
            WHERE t.status = 'ready' AND t.moderation = 'visible'
              AND {AUDIENCE}
              AND o.suspended_at IS NULL
        )
        SELECT c.id FROM c, q
        WHERE c.title LIKE q.p || '%' OR c.title LIKE '% ' || q.p || '%'
           OR c.artist LIKE q.p || '%' OR c.artist LIKE '% ' || q.p || '%'
           OR c.title % q.q OR c.artist % q.q
        ORDER BY
            (c.owner_id = :viewer) DESC,
            EXISTS (SELECT 1 FROM track_like l WHERE l.user_id = :viewer AND l.track_id = c.id) DESC,
            (c.title = q.q) DESC,
            (c.title LIKE q.p || '%' OR c.title LIKE '% ' || q.p || '%'
                OR c.artist LIKE q.p || '%' OR c.artist LIKE '% ' || q.p || '%') DESC,
            greatest(similarity(c.title, q.q), similarity(c.artist, q.q)) DESC,
            c.play_count DESC,
            c.id
        OFFSET :offset LIMIT :limit
        SQL;

    private const string USERS_SQL = <<<'SQL'
        SELECT u.id FROM app_user u, q
        WHERE u.suspended_at IS NULL AND u.id <> :viewer
          AND (
              wave_unaccent(lower(u.username::text)) LIKE q.p || '%'
              OR wave_unaccent(lower(u.display_name)) LIKE q.p || '%'
              OR wave_unaccent(lower(u.display_name)) LIKE '% ' || q.p || '%'
              OR wave_unaccent(lower(u.username::text)) % q.q
              OR wave_unaccent(lower(u.display_name)) % q.q
          )
        ORDER BY
            (wave_unaccent(lower(u.username::text)) = q.q) DESC,
            (wave_unaccent(lower(u.username::text)) LIKE q.p || '%'
                OR wave_unaccent(lower(u.display_name)) LIKE q.p || '%'
                OR wave_unaccent(lower(u.display_name)) LIKE '% ' || q.p || '%') DESC,
            greatest(similarity(wave_unaccent(lower(u.username::text)), q.q), similarity(wave_unaccent(lower(u.display_name)), q.q)) DESC,
            u.followers_count DESC,
            u.id
        OFFSET :offset LIMIT :limit
        SQL;
}
