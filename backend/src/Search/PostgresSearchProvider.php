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
 * Phase 4 (W-41), full-text (`simple` + unaccent, every token as a prefix) or trigram:
 * - artists: visible, owner not suspended. Ranking: exact name, full-text match, similarity,
 *   track count, id.
 * - playlists: public, visible, owner not suspended (the caller's private ones are not searched).
 *   Ranking: exact name, ts_rank (name A, description B), name similarity, likes, id.
 * - hashtags: only those with a post playable for the caller. Name prefix or trigram (a leading
 *   `#` is ignored). Ranking: exact, prefix, similarity, post count, id.
 * The query is always a bound parameter; the tsquery is built in SQL from the lexemes of
 * to_tsvector(), each one quoted, so tsquery operators typed by the user are never parsed.
 */
final readonly class PostgresSearchProvider implements SearchProvider
{
    public function __construct(private Connection $db)
    {
    }

    public function search(string $query, SearchType $type, Uuid $viewerId, int $offset, int $limit): SearchHits
    {
        $sql = match ($type) {
            SearchType::Users => self::QUERY_CTE.self::USERS_SQL,
            SearchType::Tracks => self::QUERY_CTE.str_replace('{AUDIENCE}', TrackAudience::sql('t'), self::TRACKS_SQL),
            SearchType::Artists => self::QUERY_CTE.self::TSQUERY_CTE.self::ARTISTS_SQL,
            SearchType::Playlists => self::QUERY_CTE.self::TSQUERY_CTE.self::PLAYLISTS_SQL,
            SearchType::Hashtags => str_replace('{PLAYABLE}', TrackAudience::postPlayableSql('p', 't', 'u'), self::HASHTAGS_SQL),
        };
        $viewer = $viewerId->toRfc4122();
        $params = match ($type) {
            SearchType::Users => ['viewer' => $viewer],
            SearchType::Tracks => ['viewer' => $viewer, 'v' => $viewer],
            SearchType::Artists, SearchType::Playlists => [],
            SearchType::Hashtags => ['v' => $viewer],
        };
        $limit = max(0, min($limit, self::MAX_RESULTS - $offset));
        if (0 === $limit) {
            return new SearchHits([], null);
        }

        $ids = $this->db->fetchFirstColumn(
            $sql,
            ['q' => trim($query), 'offset' => $offset, 'limit' => $limit + 1] + $params,
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

    /**
     * `tsq` = every lexeme of the query as a quoted prefix term, AND-ed (`'daft':* & 'pu':*`).
     * Lexemes come out of to_tsvector(), are quoted (chr(39)) with quotes and backslashes doubled,
     * so nothing typed by the user is read as tsquery syntax. No lexeme → NULL → no full-text match.
     */
    private const string TSQUERY_CTE = <<<'SQL'
        , f AS (
            SELECT (
                SELECT string_agg(chr(39) || replace(replace(l.lexeme, chr(92), chr(92) || chr(92)), chr(39), chr(39) || chr(39)) || chr(39) || ':*', ' & ')
                FROM unnest(to_tsvector('simple', q.q)) AS l
            )::tsquery AS tsq
            FROM q
        )

        SQL;

    private const string ARTISTS_SQL = <<<'SQL'
        SELECT a.id FROM artist a JOIN app_user o ON o.id = a.owner_id, q, f
        WHERE a.moderation = 'visible' AND o.suspended_at IS NULL
          AND (a.search_vector @@ f.tsq OR wave_unaccent(lower(a.name)) % q.q)
        ORDER BY
            (wave_unaccent(lower(a.name)) = q.q) DESC,
            coalesce(a.search_vector @@ f.tsq, false) DESC,
            similarity(wave_unaccent(lower(a.name)), q.q) DESC,
            a.track_count DESC,
            a.id
        OFFSET :offset LIMIT :limit
        SQL;

    private const string PLAYLISTS_SQL = <<<'SQL'
        SELECT p.id FROM playlist p JOIN app_user o ON o.id = p.owner_id, q, f
        WHERE p.is_public AND p.moderation = 'visible' AND o.suspended_at IS NULL
          AND (p.search_vector @@ f.tsq OR wave_unaccent(lower(p.name)) % q.q)
        ORDER BY
            (wave_unaccent(lower(p.name)) = q.q) DESC,
            coalesce(ts_rank(p.search_vector, f.tsq), 0) DESC,
            similarity(wave_unaccent(lower(p.name)), q.q) DESC,
            p.like_count DESC,
            p.id
        OFFSET :offset LIMIT :limit
        SQL;

    /**
     * Hashtag names are stored lowercase, not unaccented: prefix on the lowercased query, trigram
     * on it and on its unaccented form (all three served by the trigram index on `name`).
     */
    private const string HASHTAGS_SQL = <<<'SQL'
        WITH n AS (SELECT ltrim(lower(:q), '#') AS q),
        q AS (SELECT n.q, wave_unaccent(n.q) AS u,
                     replace(replace(replace(n.q, chr(92), chr(92) || chr(92)), '%', chr(92) || '%'), '_', chr(92) || '_') AS p FROM n)
        SELECT h.id FROM hashtag h, q
        WHERE q.q <> ''
          AND (h.name LIKE q.p || '%' OR h.name % q.q OR h.name % q.u)
          AND EXISTS (
              SELECT 1 FROM post_hashtag ph
              JOIN post p ON p.id = ph.post_id
              JOIN track t ON t.id = p.track_id
              JOIN app_user u ON u.id = p.author_id
              WHERE ph.hashtag_id = h.id AND {PLAYABLE}
          )
        ORDER BY
            (h.name = q.q) DESC,
            (h.name LIKE q.p || '%') DESC,
            greatest(similarity(h.name, q.q), similarity(h.name, q.u)) DESC,
            h.post_count DESC,
            h.id
        OFFSET :offset LIMIT :limit
        SQL;
}
