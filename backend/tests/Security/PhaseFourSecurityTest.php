<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Catalog\CatalogFeature;
use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use App\Repository\PostRepository;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

/**
 * W-44 (qa phase 4): reposts (ADR 015), search fuzz (W-41/W-87), Deezer catalogue (ADR 014),
 * admin series (W-82). One intended failure per open finding (see docs/handoff/w44-qa.md).
 */
final class PhaseFourSecurityTest extends SecurityTestCase
{
    // ── reposts ───────────────────────────────────────────────────────────

    /** FINDING W44-1: the repost branch of /feed/following ignores the post author's suspension. */
    public function testFollowingFeedHidesRepostOfSuspendedAuthor(): void
    {
        $alice = $this->createUser('alice'); // author, not followed by me
        $carol = $this->createUser('carol');
        $me = $this->createUser('myself');
        $post = $this->makePost($alice, $this->createTrack($this->createArtist($alice, 'A')));
        $this->api('PUT', '/api/v1/users/carol/follow', $me);
        self::assertSame(200, $this->api('PUT', '/api/v1/posts/'.$post->getId().'/repost', $carol)->getStatusCode());

        $this->sql('UPDATE app_user SET suspended_at = now() WHERE id = ?', [$alice->getId()]);

        self::assertSame(404, $this->api('GET', '/api/v1/posts/'.$post->getId(), $me)->getStatusCode(), 'suspended author: post unreachable');
        $items = $this->json($this->api('GET', '/api/v1/feed/following', $me))['items'];
        self::assertSame([], array_column($items, 'id'), 'a suspended author\'s post (and its clip grant) must not reach followers of a reposter');
        self::assertSame([], array_column($this->json($this->api('GET', '/api/v1/users/carol/reposts', $me))['items'], 'id'));
    }

    public function testStrangerRepostOnRestrictedPostIsIndistinguishableFromUnknown(): void
    {
        $alice = $this->createUser('alice');
        $stranger = $this->createUser('stranger');
        $unknown = Uuid::v7()->toRfc4122();
        foreach ([Visibility::Followers, Visibility::Private] as $i => $visibility) {
            $post = $this->makePost($alice, $this->createTrack($this->createArtist($alice, 'R'.$i, 'r'.$i), 'R'.$i, visibility: $visibility));
            foreach (['PUT', 'DELETE'] as $method) {
                $known = $this->api($method, '/api/v1/posts/'.$post->getId().'/repost', $stranger);
                $ghost = $this->api($method, '/api/v1/posts/'.$unknown.'/repost', $stranger);
                self::assertSame(404, $known->getStatusCode(), $method.' '.$visibility->value);
                self::assertSame($ghost->getContent(false), $known->getContent(false), 'same bytes as an unknown id');
            }
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM repost'));
    }

    public function testRepostSurvivesNoAudienceChangeOrUnfollowLeak(): void
    {
        $alice = $this->createUser('alice');
        $carol = $this->createUser('carol');
        $me = $this->createUser('myself');
        $track = $this->createTrack($this->createArtist($alice, 'A'));
        $post = $this->makePost($alice, $track);
        $this->api('PUT', '/api/v1/users/carol/follow', $me);
        $this->api('PUT', '/api/v1/posts/'.$post->getId().'/repost', $carol);
        self::assertCount(1, $this->json($this->api('GET', '/api/v1/feed/following', $me))['items']);

        $this->sql("UPDATE track SET visibility = 'private' WHERE id = ?", [$track->getId()]);
        self::assertSame([], $this->json($this->api('GET', '/api/v1/feed/following', $me))['items']);
        self::assertSame([], $this->json($this->api('GET', '/api/v1/users/carol/reposts', $me))['items']);
        $this->sql("UPDATE track SET visibility = 'public' WHERE id = ?", [$track->getId()]);

        $this->sql("UPDATE track SET moderation = 'hidden' WHERE id = ?", [$track->getId()]);
        self::assertSame([], $this->json($this->api('GET', '/api/v1/feed/following', $me))['items']);
        $this->sql("UPDATE track SET moderation = 'visible' WHERE id = ?", [$track->getId()]);

        $this->api('DELETE', '/api/v1/users/carol/follow', $me);
        self::assertSame([], $this->json($this->api('GET', '/api/v1/feed/following', $me))['items'], 'unfollow drops the repost path');
    }

    // ── search fuzz: never a 500 ──────────────────────────────────────────

    public function testSearchFuzzNeverErrors(): void
    {
        $me = $this->createUser('fuzzer');
        $inputs = [
            "a' & !b | (c:* <-> d) \\ '' :*",
            str_repeat('%_', 50),
            str_repeat('a b ', 25),
            "\u{FF05}\u{FF3F}\u{FB01} \u{00E9}\u{0301}",
            '##rap',
            "x\x00y",
            str_repeat('é', 100),
        ];
        $seen = 0;
        foreach ($inputs as $q) {
            foreach (['', '&type=tracks', '&type=users', '&type=artists', '&type=playlists', '&type=hashtags'] as $type) {
                $status = $this->api('GET', '/api/v1/search?q='.rawurlencode($q).$type, $me)->getStatusCode();
                self::assertContains($status, [200, 400, 422, 429], 'q='.json_encode($q).$type);
                ++$seen;
            }
        }
        foreach (['q[]=ab', 'q=ab&cursor=%00', 'q=ab&type=tracks&cursor='.str_repeat('A', 500), 'q='.str_repeat('a', 101)] as $query) {
            self::assertContains($this->api('GET', '/api/v1/search?'.$query, $me)->getStatusCode(), [400, 422, 429], $query);
        }
        self::assertGreaterThan(0, $seen);
    }

    // ── catalogue (Deezer) ────────────────────────────────────────────────

    public function testCatalogMalformedIdsNeverReachProvider(): void
    {
        $me = $this->createUser('alice');
        $mock = $this->deezer([]); // throws if called
        foreach (['dz:27%0A', 'dz:%EF%BC%92%EF%BC%97', 'dz:1234567890123456', 'dz:1%2F..%2Fchart', 'dz:27%3Fx%3D1', 'dz:-1', 'DZ:27'] as $id) {
            self::assertSame(404, $this->api('GET', '/api/v1/catalog/artists/'.$id, $me)->getStatusCode(), $id);
        }
        self::assertSame(0, $mock->getRequestsCount());
    }

    public function testCatalogQueryCannotInjectIntoOutboundRequest(): void
    {
        $me = $this->createUser('alice');
        $urls = [];
        $mock = new MockHttpClient(static function (string $method, string $url) use (&$urls): MockResponse {
            $urls[] = $url;

            return new MockResponse('{"data":[]}', ['http_code' => 200]);
        });
        static::getContainer()->set('http_client', $mock);

        $q = "w44 daft&limit=999#frag\r\n/../chart?x=@evil.example";
        self::assertSame(200, $this->api('GET', '/api/v1/catalog/artists?q='.rawurlencode($q).'&limit=999', $me)->getStatusCode());
        self::assertCount(1, $urls);
        $parts = parse_url($urls[0]);
        self::assertSame('api.deezer.com', $parts['host'] ?? null);
        self::assertSame('/search/artist', $parts['path'] ?? null);
        parse_str($parts['query'] ?? '', $query);
        self::assertSame($q, $query['q'] ?? null, 'q forwarded as one encoded value');
        self::assertLessThanOrEqual(10, (int) ($query['limit'] ?? 0), 'limit stays clamped server-side');
        self::assertArrayNotHasKey('fragment', $parts);
    }

    public function testCatalogMalformedProviderPayloadsNeverError(): void
    {
        $me = $this->createUser('alice');
        $body = '';
        static::getContainer()->set('http_client', new MockHttpClient(static function () use (&$body): MockResponse {
            return new MockResponse($body, ['http_code' => 200]);
        }));
        foreach (['null', '"x"', '[1,2]', '{"data":"x"}', '{"data":[{"id":{"a":1},"name":["x"]}]}', '{"error":"boom"}', '<html>'] as $i => $body) {
            $status = $this->api('GET', '/api/v1/catalog/artists?q=w44fuzz'.$i, $me)->getStatusCode();
            self::assertSame(200, $status, $body);
            self::assertContains($this->api('GET', '/api/v1/catalog/artists/dz:44'.$i.'01', $me)->getStatusCode(), [200, 404, 503], $body);
        }
    }

    /** FINDING W44-2: provider URLs are passed verbatim (link → <a href>, picture/preview → media). */
    public function testCatalogRejectsNonDeezerUrlsFromProvider(): void
    {
        $me = $this->createUser('alice');
        $this->deezer([
            '/artist/4499/top' => new MockResponse((string) json_encode(['data' => [[
                'id' => 1, 'title' => 'T', 'duration' => 30,
                'preview' => 'http://evil.example/a.mp3',
                'link' => 'javascript:alert(1)',
                'album' => ['title' => 'A', 'cover_xl' => 'https://evil.example/c.jpg'],
            ]]]), ['http_code' => 200]),
            '/artist/4499' => new MockResponse((string) json_encode([
                'id' => 4499, 'name' => 'X', 'picture_xl' => 'https://evil.example/p.jpg', 'link' => 'javascript:alert(1)',
            ]), ['http_code' => 200]),
        ]);

        $body = $this->json($this->api('GET', '/api/v1/catalog/artists/dz:4499', $me));
        $deezerLink = static fn (?string $u): bool => null !== $u && 1 === preg_match('#^https://www\.deezer\.com/#', $u);
        $dzcdn = static fn (?string $u): bool => null === $u || 1 === preg_match('#^https://[a-z0-9-]+\.dzcdn\.net/#', $u);
        self::assertTrue($deezerLink($body['deezerUrl'] ?? null), 'artist deezerUrl: '.json_encode($body['deezerUrl'] ?? null));
        self::assertTrue($dzcdn($body['pictureUrl'] ?? null), 'artist pictureUrl');
        self::assertTrue($deezerLink($body['topTracks'][0]['deezerUrl'] ?? null), 'track deezerUrl');
        self::assertTrue($dzcdn($body['topTracks'][0]['previewUrl'] ?? null), 'previewUrl');
        self::assertTrue($dzcdn($body['topTracks'][0]['coverUrl'] ?? null), 'coverUrl');
    }

    public function testCatalogFlagOffHidesEverythingEvenForStaff(): void
    {
        $admin = $this->createUser('boss');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em()->flush();
        $mock = $this->deezer([]);
        static::getContainer()->set(CatalogFeature::class, new CatalogFeature('none'));
        foreach (['/api/v1/catalog/artists?q=daft', '/api/v1/catalog/charts/artists', '/api/v1/catalog/artists/dz:27', '/api/v1/catalog/artists?q=x'] as $url) {
            self::assertSame(404, $this->api('GET', $url, $admin)->getStatusCode(), $url);
        }
        self::assertSame(0, $mock->getRequestsCount());
    }

    // ── admin series ──────────────────────────────────────────────────────

    public function testAdminSeriesExposesAggregatesOnly(): void
    {
        $admin = $this->createUser('boss', email: 'boss-w44@example.test');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em()->flush();
        $this->createUser('plainw44', email: 'plain-w44@example.test');

        $raw = (string) $this->api('GET', '/api/v1/admin/dashboard/series?range=30d', $admin)->getContent();
        self::assertStringNotContainsString('@', $raw);
        self::assertStringNotContainsString('plainw44', $raw);
        self::assertSame(0, preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-/', $raw), 'no ids');
        self::assertLessThan(500, $this->api('GET', '/api/v1/admin/dashboard/series?range[]=7d', $admin)->getStatusCode());
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function makePost(User $author, Track $track): Post
    {
        $post = new Post($this->managed($author), $this->managed($track), 0, 30_000, null);
        $this->em()->persist($post);
        $post->setClipKey('audio/clips/'.$post->getId().'.m4a');
        $this->em()->flush();
        self::assertTrue(static::getContainer()->get(PostRepository::class)->tryMarkReady($post->getId()));
        $this->em()->clear();

        return $post;
    }

    /** @param list<mixed> $params */
    private function sql(string $sql, array $params): void
    {
        $this->em()->getConnection()->executeStatement($sql, array_map(static fn (mixed $p): mixed => $p instanceof Uuid ? $p->toRfc4122() : $p, $params));
        $this->em()->clear();
    }

    /** @param array<string, MockResponse> $byPath */
    private function deezer(array $byPath): MockHttpClient
    {
        $mock = new MockHttpClient(static function (string $method, string $url) use ($byPath): MockResponse {
            foreach ($byPath as $path => $response) {
                if (str_contains($url, $path)) {
                    return $response;
                }
            }

            throw new \LogicException('Unexpected outbound call: '.$url);
        }, 'https://api.deezer.com');
        static::getContainer()->set('http_client', $mock);

        return $mock;
    }
}
