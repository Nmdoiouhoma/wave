<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Enum\Visibility;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * W-26 re-review (docs/handoff/w26-fix-qa.md): what the F12-F16 fixes leave untested —
 * byte-identical 404s (no existence oracle), encoding in keys/cookies/headers/double encoding,
 * grant loops, the shared `playlist_write` bucket.
 */
final class PhaseTwoFixesSecurityTest extends SecurityTestCase
{
    private const string UNKNOWN = '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e';

    /** F14/T17: DELETE …/like without a like: invisible target ≡ unknown target, body and headers. */
    public function testUnlikeWithoutLikeAnswersTheSame404ForInvisibleAndUnknown(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $private = $this->createTrack($this->createArtist($alice), 'Secret', visibility: Visibility::Private);
        $playlist = $this->createPlaylist($alice, 'Secret list', public: false);

        foreach ([['tracks', $private->getId()->toRfc4122()], ['playlists', $playlist->getId()->toRfc4122()]] as [$kind, $id]) {
            $invisible = $this->as($bob, 'DELETE', "/api/v1/$kind/$id/like");
            $unknown = $this->as($bob, 'DELETE', "/api/v1/$kind/".self::UNKNOWN.'/like');
            $malformed = $this->as($bob, 'DELETE', "/api/v1/$kind/not-a-uuid/like");
            self::assertSame(404, $invisible->getStatusCode(), (string) $invisible->getContent());
            self::assertSame(self::fingerprint($unknown), self::fingerprint($invisible), $kind);
            self::assertSame(self::fingerprint($unknown), self::fingerprint($malformed), $kind.' malformed id');
        }
    }

    /** F16: every `/users/{username}*` route answers a suspended user exactly like an unknown one. */
    public function testSuspendedUserIsIndistinguishableFromUnknownOnUserRoutes(): void
    {
        $me = $this->createUser('viewer');
        $ghost = $this->createUser('ghost_user');
        $this->createPlaylist($ghost);
        $this->createTrack($this->createArtist($ghost));
        $ghost->suspend();
        $this->em()->flush();

        foreach ([['GET', ''], ['GET', '/followers'], ['GET', '/following'], ['GET', '/tracks'], ['GET', '/playlists'], ['GET', '/artists'], ['PUT', '/follow']] as [$method, $suffix]) {
            $suspended = $this->as($me, $method, '/api/v1/users/ghost_user'.$suffix);
            $unknown = $this->as($me, $method, '/api/v1/users/nobody_here'.$suffix);
            self::assertSame(404, $suspended->getStatusCode(), $method.' '.$suffix);
            self::assertSame(self::fingerprint($unknown), self::fingerprint($suspended), $method.' '.$suffix);
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM follow'));
    }

    /** F12: NUL / invalid UTF-8 in JSON keys (top level and nested), never a 500. */
    public function testNulInJsonKeysIsRefused(): void
    {
        $alice = $this->createUser('alice');
        $playlist = $this->createPlaylist($alice);
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($alice)];

        $r = $this->raw('POST', '/api/v1/playlists', '{"name":"ok","na\u0000me":"x"}', $auth);
        self::assertSame(422, $r->getStatusCode(), (string) $r->getContent());
        $r = $this->raw('PUT', '/api/v1/playlists/'.$playlist->getId().'/order', '{"trackIds":["\u0000"]}', $auth);
        self::assertSame(422, $r->getStatusCode(), (string) $r->getContent());
        $r = $this->raw('PUT', '/api/v1/playlists/'.$playlist->getId().'/order', '{"trackIds":{"\u0000":"x"}}', $auth);
        self::assertSame(422, $r->getStatusCode(), (string) $r->getContent());
        // Lone surrogate and raw invalid UTF-8 are not JSON for PHP: 400.
        foreach (['{"name":"\ud800"}', "{\"name\":\"\xff\xfe\"}"] as $body) {
            self::assertSame(400, $this->raw('POST', '/api/v1/playlists', $body, $auth)->getStatusCode(), bin2hex($body));
        }
        self::assertSame(['Mix'], $this->em()->getConnection()->fetchFirstColumn('SELECT name FROM playlist'));
    }

    /** F12: query keys, overlong encodings, double encoding: 400 or a clean 4xx, never a 500. */
    public function testUrlEncodingEdgeCases(): void
    {
        $alice = $this->createUser('alice');
        $auth = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($alice)];

        // PHP turns `q[%00]` into an array: refused as a wrong type, which is just as clean.
        $r = $this->raw('GET', '/api/v1/search?type=users&q[%00]=ab', null, $auth);
        self::assertContains($r->getStatusCode(), [400, 422], (string) $r->getContent());
        foreach (['/api/v1/search?type=users&q=ab&%FF=1', '/api/v1/users/%C0%80alice', '/api/v1/users/%ED%A0%80alice'] as $uri) {
            $r = $this->raw('GET', $uri, null, $auth);
            self::assertSame('malformed_request', self::problemCode($r), $uri.' → '.$r->getStatusCode());
        }
        // Decoded once, like the router: `%2500` is the literal text "%00", an invalid username → 404.
        $r = $this->raw('GET', '/api/v1/users/ali%2500ce', null, $auth);
        self::assertSame(404, $r->getStatusCode(), (string) $r->getContent());
        $r = $this->raw('GET', '/api/v1/search?type=users&q=%2500%2500', null, $auth);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
    }

    /** F12: refresh token (cookie or mobile body) and bearer header with NUL / invalid UTF-8 → 401/204, not 500. */
    public function testNulInCookiesAndHeadersNeverReachPostgres(): void
    {
        $this->createUser('alice');
        foreach (["abc\0def", "abc\xff"] as $bad) {
            $this->useRefreshCookie($bad);
            self::assertSame(401, $this->raw('POST', '/api/v1/auth/refresh')->getStatusCode(), bin2hex($bad));
            self::assertSame(204, $this->raw('POST', '/api/v1/auth/logout')->getStatusCode(), bin2hex($bad));
            $this->forgetCookies();
            $mobile = ['HTTP_X_WAVE_CLIENT' => 'mobile'];
            self::assertSame(401, $this->raw('POST', '/api/v1/auth/refresh', '{"refreshToken":"abc\u0000def"}', $mobile)->getStatusCode());
            self::assertSame(401, $this->raw('GET', '/api/v1/me', null, ['HTTP_AUTHORIZATION' => 'Bearer '.$bad])->getStatusCode());
        }
        $r = $this->rawJson('POST', '/api/v1/auth/login', ['email' => "alice\0@example.test", 'password' => self::PASSWORD]);
        self::assertSame('malformed_request', self::problemCode($r), (string) $r->getContent()); // LoginRequest has no 422
    }

    /**
     * F13: an attacker re-creating a grant before each report (stream-url per listen, real
     * elapsed time simulated by back-dating) still stops at 20 counted listens per day.
     */
    public function testGrantLoopStopsAtTheDailyCap(): void
    {
        $alice = $this->createUser('alice');
        $bot = $this->createUser('bot');
        $track = $this->createTrack($this->createArtist($alice));
        $tid = $track->getId()->toRfc4122();
        $db = $this->em()->getConnection();

        $counted = 0;
        for ($i = 0; $i < 25; ++$i) {
            self::assertSame(200, $this->api('GET', "/api/v1/tracks/$tid/stream-url", $bot)->getStatusCode());
            // 200 s pass (> duration 180 s): the grant ages, earlier listens leave the anti-replay window.
            $db->executeStatement("UPDATE listen_grant SET issued_at = issued_at - interval '200 seconds'");
            $db->executeStatement("UPDATE listen SET created_at = created_at - interval '200 seconds'");
            $r = $this->api('POST', "/api/v1/tracks/$tid/listens", $bot, ['json' => ['listenedMs' => 180_000, 'source' => 'player']]);
            $counted += $this->json($r)['counted'] ? 1 : 0;
        }
        self::assertSame(20, $counted);
        self::assertSame(20, (int) $db->fetchOne('SELECT play_count FROM track WHERE id = ?', [$tid]));

        // Someone else's grant is useless: the pair is (user, track).
        $this->api('GET', "/api/v1/tracks/$tid/stream-url", $alice);
        $db->executeStatement("UPDATE listen_grant SET issued_at = issued_at - interval '200 seconds'");
        $other = $this->createUser('other');
        self::assertFalse($this->json($this->api('POST', "/api/v1/tracks/$tid/listens", $other, ['json' => ['listenedMs' => 180_000, 'source' => 'player']]))['counted']);
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM listen_grant'), "alice's grant untouched");
    }

    /** F15: `playlist_write` (600/h) is one bucket for createPlaylist and addPlaylistItem. */
    public function testPlaylistWriteBucketIsSharedByCreateAndAdd(): void
    {
        $alice = $this->createUser('alice');
        $playlist = $this->createPlaylist($alice);
        $track = $this->createTrack($this->createArtist($alice));
        /** @var RateLimiterFactoryInterface $factory */
        $factory = static::getContainer()->get('limiter.playlist_write_user');
        $factory->create($alice->getId()->toRfc4122())->consume(600);

        $this->assertProblem($this->api('POST', '/api/v1/playlists', $alice, ['json' => ['name' => 'More']]), 429, 'rate_limited');
        $this->assertProblem($this->api('POST', '/api/v1/playlists/'.$playlist->getId().'/items', $alice, ['json' => ['trackId' => $track->getId()->toRfc4122()]]), 429, 'rate_limited');
        // Other playlist writes are not in the bucket (contract): reorder and rename still work.
        self::assertSame(200, $this->api('PATCH', '/api/v1/playlists/'.$playlist->getId(), $alice, ['json' => ['name' => 'Renamed'], 'headers' => ['Content-Type' => 'application/merge-patch+json']])->getStatusCode());
    }

    // ------------------------------------------------------------- helpers ---

    private function as(User $user, string $method, string $uri): Response
    {
        return $this->raw($method, $uri, null, ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($user)]);
    }

    /** Status, body and headers, minus what legitimately varies between two requests. */
    private static function fingerprint(Response $r): string
    {
        $headers = $r->headers->all();
        unset($headers['date'], $headers['x-debug-token'], $headers['x-debug-token-link']);
        ksort($headers);

        return $r->getStatusCode()."\n".json_encode($headers)."\n".$r->getContent();
    }
}
