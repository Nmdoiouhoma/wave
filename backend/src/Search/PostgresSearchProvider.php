<?php

declare(strict_types=1);

namespace App\Search;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * v1 search on PostgreSQL (unaccent + pg_trgm). Phase 2 (W-27): users only — username or
 * display name starting with the query (also per word of the display name) or trigram
 * similarity ≥ 0.3 (`%` operator, GIN trigram indexes of DATA_MODEL §2). Ranking: exact
 * username, prefix, similarity, followers, id. Suspended users and the caller are excluded.
 */
final readonly class PostgresSearchProvider implements SearchProvider
{
    public function __construct(private Connection $db)
    {
    }

    public function search(string $query, SearchType $type, Uuid $viewerId, int $offset, int $limit): SearchHits
    {
        if (SearchType::Users !== $type) {
            throw new \LogicException('Only user search exists before phase 4.');
        }
        $limit = max(0, min($limit, self::MAX_RESULTS - $offset));
        if (0 === $limit) {
            return new SearchHits([], null);
        }
        $like = addcslashes(mb_strtolower(trim($query)), '%_\\');

        $ids = $this->db->fetchFirstColumn(
            <<<'SQL'
                WITH q AS (SELECT wave_unaccent(lower(:q)) AS q, wave_unaccent(:like) AS p)
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
                SQL,
            ['q' => trim($query), 'like' => $like, 'viewer' => $viewerId->toRfc4122(), 'offset' => $offset, 'limit' => $limit + 1],
        );

        $hasMore = \count($ids) > $limit && $offset + $limit < self::MAX_RESULTS;

        return new SearchHits(
            array_map(static fn (mixed $id): Uuid => Uuid::fromString((string) $id), \array_slice($ids, 0, $limit)),
            $hasMore ? $offset + $limit : null,
        );
    }
}
