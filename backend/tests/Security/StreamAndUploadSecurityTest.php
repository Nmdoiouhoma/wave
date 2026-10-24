<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use App\Message\ProcessImage;
use App\Message\ProcessTrack;
use App\Stream\StreamTokenSigner;
use Symfony\Component\Clock\NativeClock;

/**
 * W-16: signed stream tokens (tamper, key separation, expiry, replay after revocation)
 * and synchronous upload pre-checks (size, MIME/extension spoofing, path traversal).
 * Content-level checks (polyglots, bombs, ffmpeg tricks) are in MediaSecurityTest.
 */
final class StreamAndUploadSecurityTest extends SecurityTestCase
{
    private const string KEY = 'test-stream-signing-key';

    // ------------------------------------------------------------ stream ---

    public function testTokenTampering(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $private = $this->createTrack($this->createArtist($alice, 'B2', 'b2'), 'Secret', visibility: Visibility::Private);
        [$payload, $mac] = explode('.', $this->streamToken($bob, $track));

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        self::assertIsArray($data);
        $cases = [
            'id swapped to a private track' => self::b64((string) json_encode(['id' => $private->getId()->toRfc4122()] + $data)).'.'.$mac,
            'uid swapped to the owner' => self::b64((string) json_encode(['uid' => $alice->getId()->toRfc4122()] + $data)).'.'.$mac,
            'kind swapped' => self::b64((string) json_encode(['k' => 'c'] + $data)).'.'.$mac,
            'mac truncated' => $payload.'.'.substr($mac, 0, 20),
            'mac empty' => $payload.'.',
            'padding added' => $payload.'=.'.$mac,
            'three parts' => $payload.'.'.$mac.'.x',
            'standard base64 chars' => strtr($payload, '-_', '+/').'.'.$mac,
        ];
        $cases = array_filter($cases, static fn (string $t): bool => $t !== $payload.'.'.$mac);
        foreach ($cases as $label => $token) {
            $r = $this->raw('GET', '/api/v1/stream/'.$token);
            self::assertContains($r->getStatusCode(), [403, 404], $label.': '.$r->getStatusCode());
            self::assertNotSame(200, $r->getStatusCode(), $label);
            self::assertFalse($r->headers->has('X-Accel-Redirect'), $label);
        }
    }

    public function testTokenSignedWithAnotherSecretIsRejected(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        // Key separation: APP_SECRET (or any other app key) must not mint stream tokens.
        foreach (['test-secret-0123456789abcdef0123456789', 'dev-only-stream-key-distinct-from-app-secret'] as $other) {
            $forged = new StreamTokenSigner($other, 300, new NativeClock())->sign('t', $track->getId(), $alice->getId())['token'];
            $r = $this->raw('GET', '/api/v1/stream/'.$forged);
            self::assertSame(403, $r->getStatusCode());
            self::assertSame('stream_token_invalid', self::problemCode($r));
        }
    }

    public function testNonCanonicalPayloadsWithValidMacAreRejected(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $base = ['v' => 1, 'k' => 't', 'id' => $track->getId()->toRfc4122(), 'uid' => $alice->getId()->toRfc4122()];
        foreach ([
            'exp as string' => $base + ['exp' => (string) (time() + 300)],
            'exp as float' => $base + ['exp' => time() + 300.5],
            'version 2' => ['v' => 2] + $base + ['exp' => time() + 300],
            'uid not a uuid' => ['uid' => 'alice'] + $base + ['exp' => time() + 300],
        ] as $label => $claims) {
            $payload = (string) json_encode($claims);
            $token = self::b64($payload).'.'.self::b64(hash_hmac('sha256', $payload, self::KEY, true));
            self::assertSame(403, $this->raw('GET', '/api/v1/stream/'.$token)->getStatusCode(), $label);
        }
    }

    public function testReplayAfterRevocationIsRefused(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $t1 = $this->createTrack($this->createArtist($alice));
        $t2 = $this->createTrack($this->createArtist($alice, 'B2', 'b2'), 'Other');
        $t3 = $this->createTrack($this->createArtist($alice, 'B3', 'b3'), 'Third');
        $hidden = $this->streamToken($bob, $t1);
        $deleted = $this->streamToken($bob, $t2);
        $suspendedViewer = $this->streamToken($bob, $t3);

        $t1 = $this->reload($t1);
        $t1->setModeration(\App\Enum\Moderation::Hidden);
        $this->em()->flush();
        self::assertSame(404, $this->raw('GET', '/api/v1/stream/'.$hidden)->getStatusCode(), 'hidden by moderation');

        $this->em()->remove($this->reload($t2));
        $this->em()->flush();
        self::assertSame(404, $this->raw('GET', '/api/v1/stream/'.$deleted)->getStatusCode(), 'deleted');

        $bob = $this->reload($bob);
        $bob->suspend();
        $this->em()->flush();
        self::assertSame(404, $this->raw('GET', '/api/v1/stream/'.$suspendedViewer)->getStatusCode(), 'viewer suspended');
    }

    public function testExpiredTokenIsGone(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $expired = new StreamTokenSigner(self::KEY, 0, new NativeClock())->sign('t', $track->getId(), $alice->getId())['token'];
        $r = $this->raw('GET', '/api/v1/stream/'.$expired);
        self::assertSame(410, $r->getStatusCode());
    }

    public function testStreamEndpointOnlyServesAudioKeysAndNeverQuarantine(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $r = $this->raw('GET', '/api/v1/stream/'.$this->streamToken($alice, $track));
        self::assertSame(200, $r->getStatusCode());
        self::assertMatchesRegularExpression('#^/_protected/private/audio/tracks/[0-9a-f-]{36}\.m4a$#', (string) $r->headers->get('X-Accel-Redirect'));
        self::assertStringNotContainsString('quarantine', (string) $r->headers->get('X-Accel-Redirect'));
    }

    public function testStreamUrlIsNotCacheableAndTokenIsNotLogged(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $r = $this->api('GET', '/api/v1/tracks/'.$track->getId().'/stream-url', $alice);
        self::assertStringContainsString('no-store', $r->getHeaders()['cache-control'][0]);
    }

    // ------------------------------------------------------------ uploads ---

    public function testOversizeUploadsAre413(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $this->assertProblem($this->api('POST', '/api/v1/me/avatar', $alice, ['extra' => ['files' => ['file' => $this->sizedUpload(5_242_881, 'a.png', 'image/png')]]]), 413, 'payload_too_large');
        $this->assertProblem($this->api('POST', '/api/v1/tracks/'.$track->getId().'/cover', $alice, ['extra' => ['files' => ['file' => $this->sizedUpload(5_242_881, 'c.jpg', 'image/jpeg')]]]), 413, 'payload_too_large');
        $this->assertProblem($this->api('POST', '/api/v1/tracks', $alice, ['extra' => ['parameters' => ['title' => 'x', 'genreId' => 'pop'], 'files' => ['file' => $this->sizedUpload(31_457_281, 's.mp3', 'audio/mpeg')]]]), 413, 'payload_too_large');
        self::assertSame([], $this->queued('media'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function forbiddenDeclaredTypes(): iterable
    {
        yield 'php with image mime' => ['shell.php', 'image/png'];
        yield 'phtml' => ['a.phtml', 'image/jpeg'];
        yield 'double extension' => ['a.png.php', 'image/png'];
        yield 'svg' => ['a.svg', 'image/svg+xml'];
        yield 'html' => ['a.html', 'text/html'];
        yield 'gif' => ['a.gif', 'image/gif'];
        yield 'octet-stream without extension' => ['blob', 'application/octet-stream'];
        yield 'octet-stream with php' => ['a.php', 'application/octet-stream'];
        yield 'audio mime on avatar' => ['a.mp3', 'audio/mpeg'];
    }

    /** One request per declared type (loop keeps a single user and rate-limit bucket). */
    public function testDeclaredTypeSpoofingIsRefusedSynchronously(): void
    {
        $alice = $this->createUser('alice');
        foreach (self::forbiddenDeclaredTypes() as $label => [$name, $mime]) {
            $r = $this->api('POST', '/api/v1/me/avatar', $alice, ['extra' => ['files' => ['file' => $this->upload(self::tinyPng(), $name, $mime)]]]);
            self::assertSame(415, $r->getStatusCode(), $label.': '.$r->getContent(false));
        }
        $r = $this->api('POST', '/api/v1/tracks', $alice, ['extra' => ['parameters' => ['title' => 'x', 'genreId' => 'pop'], 'files' => ['file' => $this->upload('<?php system($_GET[1]);', 'evil.php', 'audio/mpeg')]]]);
        self::assertSame(415, $r->getStatusCode());
        self::assertSame([], $this->queued('media'));
    }

    /**
     * The declared type is only a pre-filter: content mismatching a whitelisted MIME is
     * quarantined and left to the worker (MediaSecurityTest), never served as-is.
     */
    public function testFilenameNeverReachesStorageKeys(): void
    {
        $alice = $this->createUser('alice');
        foreach (['../../../../etc/passwd.png', '..\\..\\x.png', "a\0.png", '/abs/path.png', 'é%2e%2e%2f.png'] as $name) {
            $r = $this->api('POST', '/api/v1/me/avatar', $alice, ['extra' => ['files' => ['file' => $this->upload(self::tinyPng(), $name, 'image/png')]]]);
            self::assertContains($r->getStatusCode(), [202, 415, 422], $name);
        }
        $r = $this->api('POST', '/api/v1/tracks', $alice, ['extra' => ['parameters' => ['title' => 'x', 'genreId' => 'pop'], 'files' => ['file' => $this->upload(str_repeat("\xFF\xFB", 64), '../../../../tmp/x.mp3', 'audio/mpeg')]]]);
        self::assertSame(202, $r->getStatusCode(), $r->getContent(false));

        foreach ($this->queued('media', ProcessImage::class) as $m) {
            self::assertMatchesRegularExpression('#^quarantine/avatars/users/[0-9a-f-]{36}/[0-9a-f-]{36}$#', $m->quarantineKey);
        }
        self::assertCount(1, $this->queued('media', ProcessTrack::class));
        $track = $this->em()->getRepository(Track::class)->findOneBy(['title' => 'x']);
        self::assertNotNull($track);
        self::assertMatchesRegularExpression('#^quarantine/tracks/[0-9a-f-]{36}/source$#', (string) $track->getRawKey());
    }

    public function testMultipartFieldInjectionOnTrackUpload(): void
    {
        $alice = $this->createUser('alice');
        $r = $this->api('POST', '/api/v1/tracks', $alice, ['extra' => [
            'parameters' => ['title' => 'x', 'genreId' => 'pop', 'status' => 'ready', 'audioKey' => 'audio/tracks/stolen.m4a', 'visibility' => 'public'],
            'files' => ['file' => $this->upload(str_repeat("\xFF\xFB", 64), 's.mp3', 'audio/mpeg')],
        ]]);
        self::assertSame(202, $r->getStatusCode(), $r->getContent(false));
        $track = $this->em()->getRepository(Track::class)->findOneBy(['title' => 'x']);
        self::assertNotNull($track);
        self::assertNull($track->getAudioKey());
        self::assertSame('processing', $track->getStatus()->value);
        self::assertSame(['x'], [$this->json($r)['title'] ?? null]);
    }

    // -------------------------------------------------------------- helpers ---

    private function streamToken(User $viewer, Track $track): string
    {
        $r = $this->api('GET', '/api/v1/tracks/'.$track->getId().'/stream-url', $viewer);
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
        $url = $this->json($r)['url'];

        return substr((string) parse_url($url, \PHP_URL_PATH), \strlen('/api/v1/stream/'));
    }
}
