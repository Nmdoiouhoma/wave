<?php

declare(strict_types=1);

namespace App\Catalog;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Deezer-backed catalogue (ADR 014). Base `https://api.deezer.com`, no key. Read-only; no Wave
 * row, no stored audio, no listen/affinity. Every value crosses the wire verbatim from the
 * provider — `previewUrl` especially is never wrapped in a Wave URL.
 *
 * Isolation and failure handling (ADR 014 §7):
 * - the only user input placed in a URL path is the artist's digits (validated `^[0-9]{1,15}$`);
 *   `q` goes through the query string, URL-encoded by HttpClient. No SSRF, no path traversal.
 * - connect 2 s / total 3 s, no retry; a shared outbound limiter (40 / 5 s) guards the provider;
 * - a timeout, non-2xx, invalid JSON, a 200 body with `error`, or the limiter being exhausted
 *   is a failure. A failure never bubbles up as a 500: list calls return `available: false`,
 *   the artist call serves stale metadata with `topTracks: []` or throws {@see CatalogUnavailable}.
 *
 * Cache (`cache.catalog`, DBAL, shared by workers): search/chart 1 h, artist metadata 24 h,
 * top tracks 10 min. Failures and empty results are never cached.
 */
final readonly class DeezerCatalogProvider implements CatalogProvider
{
    private const string BASE_URL = 'https://api.deezer.com';
    private const float TIMEOUT = 2.0;
    private const float MAX_DURATION = 3.0;
    private const int TTL_LIST = 3600;
    private const int TTL_META = 86400;
    private const int TTL_TRACKS = 600;
    private const int MAX_TRACKS = 10;

    /** Deezer error code for an unknown/absent resource (DataException "no data"). */
    private const int ERROR_NO_DATA = 800;

    public function __construct(
        private HttpClientInterface $httpClient,
        #[Autowire(service: 'cache.catalog')]
        private CacheItemPoolInterface $cache,
        #[Autowire(service: 'limiter.catalog_outbound')]
        private RateLimiterFactoryInterface $outboundLimiter,
        private LoggerInterface $logger,
    ) {
    }

    public function searchArtists(string $query, int $limit): CatalogResult
    {
        $key = 'cat:search:'.sha1($this->normalise($query)).':'.$limit;

        return $this->cachedList($key, '/search/artist', ['q' => $query, 'limit' => $limit], $limit);
    }

    public function chartArtists(int $limit): CatalogResult
    {
        return $this->cachedList('cat:chart:'.$limit, '/chart/0/artists', ['limit' => $limit], $limit);
    }

    public function artist(string $externalId): ?CatalogArtist
    {
        // Defence in depth: the route already validates the id, but no outbound call is ever
        // made for a malformed id (so `dz:abc` or `../` never reach Deezer).
        if (1 !== preg_match('/^dz:([0-9]{1,15})$/', $externalId, $m)) {
            return null;
        }
        $id = $m[1];

        $summary = $this->artistSummary($id); // null = unknown (404), throws CatalogUnavailable when down
        if (null === $summary) {
            return null;
        }

        return new CatalogArtist($summary, $this->topTracks($id));
    }

    /** @throws CatalogUnavailable when down and no metadata is cached */
    private function artistSummary(string $id): ?CatalogArtistSummary
    {
        $item = $this->cache->getItem('cat:artist:meta:'.$id);
        if ($item->isHit()) {
            /* @var CatalogArtistSummary */
            return $item->get();
        }

        $body = $this->request('/artist/'.$id, []); // may throw CatalogUnavailable (down → 503)
        if (self::ERROR_NO_DATA === $this->errorCode($body)) {
            return null; // unknown id → 404
        }
        if (null !== $this->errorCode($body) || !isset($body['id'])) {
            throw new CatalogUnavailable('Artist metadata unavailable.');
        }

        $summary = $this->mapSummary($body);
        $item->set($summary)->expiresAfter(self::TTL_META);
        $this->cache->save($item);

        return $summary;
    }

    /**
     * Top tracks (10 min). On provider failure the artist page degrades to an empty list rather
     * than a 503 (the metadata is already in hand); the empty list is not cached.
     *
     * @return list<CatalogTrack>
     */
    private function topTracks(string $id): array
    {
        $item = $this->cache->getItem('cat:artist:tracks:'.$id);
        if ($item->isHit()) {
            /* @var list<CatalogTrack> */
            return $item->get();
        }

        try {
            $body = $this->request('/artist/'.$id.'/top', ['limit' => self::MAX_TRACKS]);
        } catch (CatalogUnavailable) {
            return [];
        }
        if (null !== $this->errorCode($body) || !isset($body['data']) || !\is_array($body['data'])) {
            return [];
        }

        $tracks = [];
        foreach (\array_slice($body['data'], 0, self::MAX_TRACKS) as $row) {
            if (\is_array($row) && isset($row['id'])) {
                $tracks[] = $this->mapTrack($row);
            }
        }
        $item->set($tracks)->expiresAfter(self::TTL_TRACKS);
        $this->cache->save($item);

        return $tracks;
    }

    /**
     * @param array<string, scalar|int> $query
     */
    private function cachedList(string $key, string $path, array $query, int $limit): CatalogResult
    {
        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            /* @var CatalogResult */
            return $item->get();
        }

        try {
            $body = $this->request($path, $query);
        } catch (CatalogUnavailable) {
            return CatalogResult::unavailable();
        }
        if (null !== $this->errorCode($body) || !isset($body['data']) || !\is_array($body['data'])) {
            return CatalogResult::unavailable();
        }

        $items = [];
        foreach (\array_slice($body['data'], 0, $limit) as $row) {
            if (\is_array($row) && isset($row['id'])) {
                $items[] = $this->mapSummary($row);
            }
        }
        $result = CatalogResult::of($items);
        $item->set($result)->expiresAfter(self::TTL_LIST);
        $this->cache->save($item);

        return $result;
    }

    /**
     * One outbound GET, guarded by the shared limiter and the timeouts. Returns the decoded body
     * for any usable 2xx JSON response (the caller inspects `error`).
     *
     * @param array<string, scalar|int> $query
     *
     * @return array<string, mixed>
     *
     * @throws CatalogUnavailable on limiter exhaustion, transport error, non-2xx or invalid JSON
     */
    private function request(string $path, array $query): array
    {
        if (!$this->outboundLimiter->create('catalog')->consume()->isAccepted()) {
            $this->logger->warning('Catalog outbound limiter exhausted', ['path' => $path]);

            throw new CatalogUnavailable('Outbound limiter exhausted.');
        }

        try {
            $response = $this->httpClient->request('GET', self::BASE_URL.$path, [
                'query' => $query,
                'timeout' => self::TIMEOUT,
                'max_duration' => self::MAX_DURATION,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 300) {
                throw new CatalogUnavailable(\sprintf('Provider HTTP %d.', $status));
            }

            /** @var array<string, mixed> $body */
            $body = $response->toArray(false);

            return $body;
        } catch (CatalogUnavailable $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->warning('Catalog provider request failed', ['path' => $path, 'error' => $e->getMessage()]);

            throw new CatalogUnavailable('Provider request failed.', 0, $e);
        }
    }

    /** @param array<string, mixed> $body */
    private function errorCode(array $body): ?int
    {
        if (!isset($body['error'])) {
            return null;
        }

        return \is_array($body['error']) && isset($body['error']['code']) ? (int) $body['error']['code'] : -1;
    }

    /** @param array<string, mixed> $row */
    private function mapSummary(array $row): CatalogArtistSummary
    {
        return new CatalogArtistSummary(
            'dz:'.$this->scalar($row['id']),
            $this->scalar($row['name'] ?? ''),
            $this->urlOrNull($row['picture_xl'] ?? null),
            isset($row['nb_fan']) && is_numeric($row['nb_fan']) ? (int) $row['nb_fan'] : null,
            $this->deezerLink($row['link'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    private function mapTrack(array $row): CatalogTrack
    {
        $album = \is_array($row['album'] ?? null) ? $row['album'] : [];

        return new CatalogTrack(
            'dz:'.$this->scalar($row['id']),
            $this->scalar($row['title'] ?? ''),
            isset($row['duration']) && is_numeric($row['duration']) ? (int) $row['duration'] : 0,
            $this->urlOrNull($row['preview'] ?? null), // verbatim provider preview URL
            $this->stringOrNull($album['title'] ?? null),
            $this->urlOrNull($album['cover_xl'] ?? null),
            $this->deezerLink($row['link'] ?? null),
        );
    }

    private function scalar(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) && '' !== $value ? $value : null;
    }

    /** F33: media URLs (picture, cover, preview) are kept only when served by the Deezer CDN. */
    private function urlOrNull(mixed $value): ?string
    {
        return \is_string($value) && 1 === preg_match('~^https://[a-z0-9-]+\.dzcdn\.net/[^\s"<>\\\\]*$~iD', $value) ? $value : null;
    }

    /** F33: the outbound link (rendered as an href) must point to www.deezer.com, else the Deezer home. */
    private function deezerLink(mixed $value): string
    {
        return \is_string($value) && 1 === preg_match('~^https://www\.deezer\.com/[^\s"<>\\\\]*$~iD', $value)
            ? $value
            : 'https://www.deezer.com/';
    }

    private function normalise(string $query): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $query)));
    }
}
