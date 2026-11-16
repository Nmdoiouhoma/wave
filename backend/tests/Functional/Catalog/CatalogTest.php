<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Catalog\CatalogFeature;
use App\Tests\Functional\WaveApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * W-84 (FB-06, ADR 014): external read-only catalogue (Deezer) behind the `CATALOG_PROVIDER`
 * flag. The real Deezer is never reached: every test wires a {@see MockHttpClient}. The flag is
 * `deezer` under test (phpunit.dist.xml); flag-off cases toggle {@see CatalogFeature}.
 */
final class CatalogTest extends WaveApiTestCase
{
    private const string ARTIST = '/api/v1/catalog/artists/dz:27';

    // ── mapping / happy path ──────────────────────────────────────────────

    public function testSearchReturnsArtistsWithAttribution(): void
    {
        $this->createUser('alice');
        $this->mockDeezer(['/search/artist' => $this->artistList()]);

        $response = $this->api('GET', '/api/v1/catalog/artists?q=daft', $this->userAlice());

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertTrue($body['available']);
        self::assertSame('dz:27', $body['items'][0]['id']);
        self::assertSame('Daft Punk', $body['items'][0]['name']);
        self::assertSame('https://e-cdns-images.dzcdn.net/27.jpg', $body['items'][0]['pictureUrl']);
        self::assertSame(1000, $body['items'][0]['fanCount']);
        self::assertSame(['text' => 'Données et extraits : Deezer', 'url' => 'https://www.deezer.com'], $body['attribution']);
    }

    public function testChartReturnsArtists(): void
    {
        $this->createUser('alice');
        $mock = $this->mockDeezer(['/chart/0/artists' => $this->artistList()]);

        $response = $this->api('GET', '/api/v1/catalog/charts/artists', $this->userAlice());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->json($response)['available']);
        self::assertSame(1, $mock->getRequestsCount());
    }

    public function testArtistWithTopTracksReturnsPreviewUrlVerbatim(): void
    {
        $this->createUser('alice');
        $preview = 'https://cdnt-preview.dzcdn.net/stream/abc-30.mp3';
        $this->mockDeezer([
            '/artist/27/top' => $this->topTracks($preview),
            '/artist/27' => $this->artist(),
        ]);

        $response = $this->api('GET', self::ARTIST, $this->userAlice());

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('dz:27', $body['id']);
        self::assertCount(1, $body['topTracks']);
        // previewUrl is Deezer's URL, verbatim — no Wave URL wraps it.
        self::assertSame($preview, $body['topTracks'][0]['previewUrl']);
        self::assertSame('https://www.deezer.com', $body['attribution']['url']);
    }

    public function testNullPreviewStaysNull(): void
    {
        $this->createUser('alice');
        $this->mockDeezer([
            '/artist/27/top' => $this->topTracks(''),
            '/artist/27' => $this->artist(),
        ]);

        $response = $this->api('GET', self::ARTIST, $this->userAlice());

        self::assertNull($this->json($response)['topTracks'][0]['previewUrl']);
    }

    // ── flag off ──────────────────────────────────────────────────────────

    public function testFlagOffReturns404OnEveryRouteWithoutOutboundCall(): void
    {
        $this->createUser('alice');
        $mock = $this->mockDeezer([]); // throws if called
        $this->disableCatalog();

        foreach (['/api/v1/catalog/artists?q=daft', '/api/v1/catalog/charts/artists', self::ARTIST] as $url) {
            $this->assertProblem($this->api('GET', $url, $this->userAlice()), 404, 'not_found');
        }
        self::assertSame(0, $mock->getRequestsCount());
    }

    public function testFeaturesCatalogFalseWhenFlagOff(): void
    {
        $this->createUser('alice');
        $this->disableCatalog();

        $response = $this->api('GET', '/api/v1/me', $this->userAlice());

        self::assertSame(['catalog' => false], $this->json($response)['features']);
    }

    // ── bad input: no SSRF, no path injection, no outbound call ────────────

    public function testBadArtistIdReturns404WithoutOutboundCall(): void
    {
        $this->createUser('alice');
        $mock = $this->mockDeezer([]); // throws if called

        foreach (['dz:abc', 'dz:12x', '27', '..%2f27', 'dz:'] as $id) {
            $this->assertProblem($this->api('GET', '/api/v1/catalog/artists/'.$id, $this->userAlice()), 404, 'not_found');
        }
        self::assertSame(0, $mock->getRequestsCount());
    }

    public function testSearchQueryTooShortIsRejected(): void
    {
        $this->createUser('alice');
        $this->mockDeezer([]); // throws if called — validation happens before any outbound call

        $this->assertProblem($this->api('GET', '/api/v1/catalog/artists?q=a', $this->userAlice()), 422, 'validation_failed');
    }

    // ── provider failures never 500 ────────────────────────────────────────

    /** @return iterable<string, array{MockResponse}> */
    public static function listFailureProvider(): iterable
    {
        yield 'http 500' => [new MockResponse('', ['http_code' => 500])];
        yield 'error body 200' => [new MockResponse((string) json_encode(['error' => ['type' => 'Quota', 'code' => 4]]), ['http_code' => 200])];
    }

    #[DataProvider('listFailureProvider')]
    public function testSearchFailureReturns200AvailableFalse(MockResponse $failure): void
    {
        $this->createUser('alice');
        $this->mockDeezer(['/search/artist' => $failure]);

        $response = $this->api('GET', '/api/v1/catalog/artists?q=daft', $this->userAlice());

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertFalse($body['available']);
        self::assertSame([], $body['items']);
        self::assertArrayHasKey('attribution', $body); // attribution present even on failure
    }

    public function testSearchTimeoutReturns200AvailableFalse(): void
    {
        $this->createUser('alice');
        $this->useHttpMock(new MockHttpClient(static function (): MockResponse {
            throw new TransportException('timeout');
        }));

        $response = $this->api('GET', '/api/v1/catalog/artists?q=daft', $this->userAlice());

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->json($response)['available']);
    }

    public function testArtistDownWithNothingCachedReturns503WithRetryAfter(): void
    {
        $this->createUser('alice');
        $this->useHttpMock(new MockHttpClient(static function (): MockResponse {
            throw new TransportException('timeout');
        }));

        $response = $this->api('GET', self::ARTIST, $this->userAlice());

        $this->assertProblem($response, 503, 'catalog_unavailable');
        self::assertNotEmpty($response->getHeaders(false)['retry-after'][0] ?? '');
    }

    public function testArtistUnknownAtProviderReturns404(): void
    {
        $this->createUser('alice');
        // Deezer answers an unknown id with HTTP 200 + error code 800 (DataException).
        $this->mockDeezer(['/artist/27' => new MockResponse((string) json_encode(['error' => ['type' => 'DataException', 'code' => 800]]), ['http_code' => 200])]);

        $this->assertProblem($this->api('GET', self::ARTIST, $this->userAlice()), 404, 'not_found');
    }

    public function testArtistServesMetadataWithEmptyTracksWhenTopTracksFail(): void
    {
        $this->createUser('alice');
        $this->mockDeezer([
            '/artist/27/top' => new MockResponse('', ['http_code' => 500]),
            '/artist/27' => $this->artist(),
        ]);

        $response = $this->api('GET', self::ARTIST, $this->userAlice());

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame('dz:27', $body['id']);
        self::assertSame([], $body['topTracks']); // degraded, never a 500
    }

    // ── caching and the outbound limiter ───────────────────────────────────

    public function testCacheHitMakesNoSecondOutboundCall(): void
    {
        $this->createUser('alice');
        $mock = $this->mockDeezer(['/search/artist' => $this->artistList()]);

        $this->api('GET', '/api/v1/catalog/artists?q=daft', $this->userAlice());
        $this->api('GET', '/api/v1/catalog/artists?q=daft', $this->userAlice());

        self::assertSame(1, $mock->getRequestsCount());
    }

    public function testOutboundLimiterExhaustedDegradesWithoutOutboundCall(): void
    {
        $this->createUser('alice');
        $mock = $this->mockDeezer([]); // throws if called

        /** @var RateLimiterFactoryInterface $limiter */
        $limiter = static::getContainer()->get('limiter.catalog_outbound');
        $limiter->create('catalog')->consume(40); // drain the bucket

        $response = $this->api('GET', '/api/v1/catalog/artists?q=daft', $this->userAlice());

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->json($response)['available']);
        self::assertSame(0, $mock->getRequestsCount());
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function userAlice(): \App\Entity\User
    {
        return $this->managed($this->em()->getRepository(\App\Entity\User::class)->findOneBy(['username' => 'alice']) ?? throw new \LogicException('alice missing'));
    }

    private function disableCatalog(): void
    {
        static::getContainer()->set(CatalogFeature::class, new CatalogFeature('none'));
    }

    /**
     * Routes by URL substring to a MockResponse; an unlisted path throws (proving no stray call).
     *
     * @param array<string, MockResponse> $byPath
     */
    private function mockDeezer(array $byPath): MockHttpClient
    {
        $mock = new MockHttpClient(static function (string $method, string $url) use ($byPath): MockResponse {
            foreach ($byPath as $path => $response) {
                if (str_contains($url, $path)) {
                    return $response;
                }
            }

            throw new \LogicException('Unexpected outbound call: '.$url);
        }, 'https://api.deezer.com');
        $this->useHttpMock($mock);

        return $mock;
    }

    private function useHttpMock(MockHttpClient $mock): void
    {
        static::getContainer()->set('http_client', $mock);
    }

    private function artistList(): MockResponse
    {
        return new MockResponse((string) json_encode(['data' => [[
            'id' => 27,
            'name' => 'Daft Punk',
            'picture_xl' => 'https://e-cdns-images.dzcdn.net/27.jpg',
            'nb_fan' => 1000,
            'link' => 'https://www.deezer.com/artist/27',
        ]]]), ['http_code' => 200]);
    }

    private function artist(): MockResponse
    {
        return new MockResponse((string) json_encode([
            'id' => 27,
            'name' => 'Daft Punk',
            'picture_xl' => 'https://e-cdns-images.dzcdn.net/27.jpg',
            'nb_fan' => 1000,
            'link' => 'https://www.deezer.com/artist/27',
        ]), ['http_code' => 200]);
    }

    private function topTracks(string $preview): MockResponse
    {
        return new MockResponse((string) json_encode(['data' => [[
            'id' => 3135556,
            'title' => 'Harder Better Faster Stronger',
            'duration' => 224,
            'preview' => $preview,
            'link' => 'https://www.deezer.com/track/3135556',
            'album' => ['title' => 'Discovery', 'cover_xl' => 'https://e-cdns-images.dzcdn.net/cover.jpg'],
        ]]]), ['http_code' => 200]);
    }
}
