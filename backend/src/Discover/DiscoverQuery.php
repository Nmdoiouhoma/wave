<?php

declare(strict_types=1);

namespace App\Discover;

use App\Feed\ForYouCursor;
use App\Security\TrackAudience;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * SQL of `GET /discover` (FB-32, W-87). Public-only (ADR 012 §6): a track counts when it is
 * public-visible (DATA_MODEL §6) and its owner is not suspended; a post when it is playable for a
 * viewer outside every restricted audience ({@see TrackAudience::publicPostPlayableSql()}).
 *
 * The global parts (trending ids, genre ranking, trending hashtags: 15 min; new ids: 5 min) are
 * cached in `cache.discover`; the per-viewer parts (genre affinity, suggested users, For you) are
 * not. Only ids and small scalars are cached; the caller re-checks tracks on hydration.
 */
final readonly class DiscoverQuery
{
    public const int TRENDING_LIMIT = 10;
    public const int GENRES_LIMIT = 12;
    public const int USERS_LIMIT = 6;
    public const int HASHTAGS_LIMIT = 10;
    public const int FOR_YOU_LIMIT = 10;
    public const int FOR_YOU_PER_ARTIST = 3;
    public const int NEW_LIMIT = 12;
    /** New tracks cached beyond the limit so that dropping the caller's own ones rarely needs a direct query. */
    public const int NEW_CACHED = 24;
    public const int HASHTAG_WINDOW_DAYS = 7;
    public const int GLOBAL_TTL = 900;
    public const int NEW_TTL = 300;

    /** Public-visible track `$t` with a non-suspended owner `$o`. */
    private const string PUBLIC_TRACK = "t.status = 'ready' AND t.visibility = 'public' AND t.moderation = 'visible' AND o.suspended_at IS NULL";

    public function __construct(
        private Connection $db,
        private ClockInterface $clock,
        #[Autowire(service: 'cache.discover')]
        private CacheInterface $cache,
    ) {
    }

    /** @return list<string> track ids, trending_score desc, id desc (partial index of DATA_MODEL §6) */
    public function trendingTrackIds(): array
    {
        /* @var list<string> */
        return $this->cache->get('discover_trending', function (ItemInterface $item): array {
            $item->expiresAfter(self::GLOBAL_TTL);

            return array_map('strval', $this->db->fetchFirstColumn(
                'SELECT t.id FROM track t JOIN app_user o ON o.id = t.owner_id
                 WHERE '.self::PUBLIC_TRACK.'
                 ORDER BY t.trending_score DESC, t.id DESC
                 LIMIT '.self::TRENDING_LIMIT,
            ));
        });
    }

    /**
     * @return list<array{id: string, ownerId: string}> newest public-visible tracks (created_at desc,
     *                                                  id desc), more than needed (see NEW_CACHED)
     */
    public function newTrackCandidates(): array
    {
        /* @var list<array{id: string, ownerId: string}> */
        return $this->cache->get('discover_new', function (ItemInterface $item): array {
            $item->expiresAfter(self::NEW_TTL);

            return $this->newTracks(null, self::NEW_CACHED);
        });
    }

    /**
     * Direct query, used when the cached candidates minus the caller's own tracks fall short.
     *
     * @return list<array{id: string, ownerId: string}>
     */
    public function newTracks(?string $excludedOwnerId, int $limit): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT t.id, t.owner_id FROM track t JOIN app_user o ON o.id = t.owner_id
             WHERE '.self::PUBLIC_TRACK.(null === $excludedOwnerId ? '' : ' AND t.owner_id <> :me').'
             ORDER BY t.created_at DESC, t.id DESC
             LIMIT '.$limit,
            null === $excludedOwnerId ? [] : ['me' => $excludedOwnerId],
        );

        return array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'ownerId' => (string) $r['owner_id']], $rows);
    }

    /** @return list<array{id: string, name: string}> every genre, by public-visible track count desc then name */
    public function genresByTrackCount(): array
    {
        /* @var list<array{id: string, name: string}> */
        return $this->cache->get('discover_genres', function (ItemInterface $item): array {
            $item->expiresAfter(self::GLOBAL_TTL);
            $rows = $this->db->fetchAllAssociative(
                'SELECT g.id, g.name FROM genre g
                 LEFT JOIN (
                     SELECT t.genre_id, count(*) AS n FROM track t JOIN app_user o ON o.id = t.owner_id
                     WHERE '.self::PUBLIC_TRACK.' GROUP BY t.genre_id
                 ) c ON c.genre_id = g.id
                 ORDER BY coalesce(c.n, 0) DESC, g.name, g.id',
            );

            return array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'name' => (string) $r['name']], $rows);
        });
    }

    /** @return list<string> the viewer's genre ids by affinity score desc, then key */
    public function genreAffinity(string $viewerId): array
    {
        return array_map('strval', $this->db->fetchFirstColumn(
            "SELECT key FROM user_affinity WHERE user_id = :v AND kind = 'genre' ORDER BY score DESC, key",
            ['v' => $viewerId],
        ));
    }

    /**
     * Never the viewer, a followed or a suspended user; only users with a public-visible track or a
     * publicly playable post. Ranked by followees of the viewer who follow them, followers, id.
     *
     * @return list<string> user ids
     */
    public function suggestedUserIds(string $viewerId): array
    {
        return array_map('strval', $this->db->fetchFirstColumn(
            'WITH mine AS (SELECT followed_id FROM follow WHERE follower_id = :v),
             mutual AS (
                 SELECT f.followed_id AS id, count(*) AS n FROM follow f JOIN mine m ON f.follower_id = m.followed_id
                 GROUP BY f.followed_id
             )
             SELECT u.id FROM app_user u
             LEFT JOIN mutual ON mutual.id = u.id
             WHERE u.suspended_at IS NULL AND u.id <> :v
               AND u.id NOT IN (SELECT followed_id FROM mine)
               AND (
                   EXISTS (SELECT 1 FROM track t JOIN app_user o ON o.id = t.owner_id WHERE t.owner_id = u.id AND '.self::PUBLIC_TRACK.')
                   OR EXISTS (SELECT 1 FROM post p JOIN track t ON t.id = p.track_id WHERE p.author_id = u.id AND '.TrackAudience::publicPostPlayableSql('p', 't', 'u').')
               )
             ORDER BY coalesce(mutual.n, 0) DESC, u.followers_count DESC, u.id
             LIMIT '.self::USERS_LIMIT,
            ['v' => $viewerId],
        ));
    }

    /**
     * Hashtags with at least one publicly playable post (never an empty one), by publicly playable
     * posts created in the last 7 days desc, then `post_count` desc, then name. Ids only: the
     * count shown is the caller's (fb-1124), computed on hydration.
     *
     * @return list<string> hashtag ids
     */
    public function trendingHashtagIds(): array
    {
        /* @var list<string> */
        return $this->cache->get('discover_hashtag_ids', function (ItemInterface $item): array {
            $item->expiresAfter(self::GLOBAL_TTL);
            $playable = TrackAudience::publicPostPlayableSql('p', 't', 'u');
            $rows = $this->db->fetchAllAssociative(
                'WITH live AS (
                     SELECT ph.hashtag_id, count(*) FILTER (WHERE p.created_at > CAST(:since AS timestamptz)) AS recent
                     FROM post_hashtag ph
                     JOIN post p ON p.id = ph.post_id
                     JOIN track t ON t.id = p.track_id
                     JOIN app_user u ON u.id = p.author_id
                     WHERE '.$playable.'
                     GROUP BY ph.hashtag_id
                 )
                 SELECT h.id FROM hashtag h JOIN live ON live.hashtag_id = h.id
                 ORDER BY live.recent DESC, h.post_count DESC, h.name
                 LIMIT '.self::HASHTAGS_LIMIT,
                ['since' => ForYouCursor::formatTimestamp($this->clock->now()->sub(new \DateInterval('P'.self::HASHTAG_WINDOW_DAYS.'D')))],
            );

            return array_map(static fn (array $r): string => (string) $r['id'], $rows);
        });
    }

    /**
     * Public-visible tracks whose artist or genre is in the viewer's affinity, not owned, liked or
     * listened to by the viewer; weight = the higher of the two affinity scores, which also gives
     * the reason (artist on a tie). At most 3 per artist; weight desc, trending_score desc, id desc.
     *
     * @return list<array{id: string, reason: 'artist'|'genre'}>
     */
    public function forYou(string $viewerId): array
    {
        $rows = $this->db->fetchAllAssociative(
            "WITH c AS (
                 SELECT t.id, t.artist_id, t.trending_score, aa.score AS a, ag.score AS g,
                        greatest(coalesce(aa.score, 0), coalesce(ag.score, 0)) AS w
                 FROM track t
                 JOIN app_user o ON o.id = t.owner_id
                 LEFT JOIN user_affinity aa ON aa.user_id = :v AND aa.kind = 'artist' AND aa.key = CAST(t.artist_id AS text)
                 LEFT JOIN user_affinity ag ON ag.user_id = :v AND ag.kind = 'genre' AND ag.key = t.genre_id
                 WHERE ".self::PUBLIC_TRACK." AND t.owner_id <> :v
                   AND (t.artist_id IN (SELECT CAST(key AS uuid) FROM user_affinity WHERE user_id = :v AND kind = 'artist')
                        OR t.genre_id IN (SELECT key FROM user_affinity WHERE user_id = :v AND kind = 'genre'))
                   AND NOT EXISTS (SELECT 1 FROM track_like l WHERE l.user_id = :v AND l.track_id = t.id)
                   AND NOT EXISTS (SELECT 1 FROM listen li WHERE li.user_id = :v AND li.track_id = t.id)
             ), r AS (
                 SELECT c.*, row_number() OVER (PARTITION BY c.artist_id ORDER BY c.w DESC, c.trending_score DESC, c.id DESC) AS rn FROM c
             )
             SELECT r.id, CASE WHEN coalesce(r.a, -1) >= coalesce(r.g, -1) THEN 'artist' ELSE 'genre' END AS reason
             FROM r WHERE r.rn <= ".self::FOR_YOU_PER_ARTIST.'
             ORDER BY r.w DESC, r.trending_score DESC, r.id DESC
             LIMIT '.self::FOR_YOU_LIMIT,
            ['v' => $viewerId],
        );

        return array_map(static fn (array $r): array => ['id' => (string) $r['id'], 'reason' => 'artist' === $r['reason'] ? 'artist' : 'genre'], $rows);
    }
}
