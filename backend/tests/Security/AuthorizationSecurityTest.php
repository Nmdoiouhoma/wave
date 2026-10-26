<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Track;
use App\Entity\User;
use App\Enum\FailureReason;
use App\Enum\Moderation;
use App\Enum\Visibility;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * W-16: verified-email gate, 404-not-403 for invisible tracks/artists, IDOR on
 * artists/tracks/me, mass assignment.
 */
final class AuthorizationSecurityTest extends SecurityTestCase
{
    // ------------------------------------------------- verified-email gate ---

    /**
     * Every `x-requires-verified-email` operation of phase 1, with path variants that a
     * naive gate (path matching, method override) could miss.
     */
    public function testUnverifiedUserIsStoppedOnEveryGatedOperation(): void
    {
        $carol = $this->createUser('carol', verified: false);
        $artist = $this->createArtist($carol, 'Carol Band', 'carol-band');
        $track = $this->createTrack($artist);
        $png = fn () => ['file' => $this->upload(self::tinyPng(), 'a.png', 'image/png')];

        $calls = [
            'uploadMyAvatar' => ['POST', '/api/v1/me/avatar', ['extra' => ['files' => $png()]]],
            'createArtist' => ['POST', '/api/v1/artists', ['json' => ['name' => 'Another']]],
            'updateArtist' => ['PATCH', '/api/v1/artists/carol-band', ['json' => ['name' => 'Renamed'], 'headers' => ['Content-Type' => 'application/merge-patch+json']]],
            'uploadArtistAvatar' => ['POST', '/api/v1/artists/carol-band/avatar', ['extra' => ['files' => $png()]]],
            'createTrackUpload' => ['POST', '/api/v1/tracks/uploads', ['json' => ['filename' => 's.mp3', 'size' => 128, 'contentType' => 'audio/mpeg']]],
            'createTrack' => ['POST', '/api/v1/tracks', ['json' => ['uploadId' => '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e', 'title' => 'x', 'genreId' => 'pop']]],
            'uploadTrackCover' => ['POST', '/api/v1/tracks/'.$track->getId().'/cover', ['extra' => ['files' => $png()]]],
        ];
        foreach ($calls as $op => [$method, $url, $options]) {
            $this->assertProblem($this->api($method, $url, $carol, $options), 403, 'email_not_verified');
        }

        // Bypass attempts: method override, trailing slash, case, format suffix, double slash.
        $variants = [
            ['POST', '/api/v1/artists', ['json' => ['name' => 'X'], 'headers' => ['X-HTTP-Method-Override' => 'GET']]],
            ['POST', '/api/v1/artists?_method=GET', ['json' => ['name' => 'X']]],
            ['POST', '/api/v1/artists/', ['json' => ['name' => 'X']]],
            ['POST', '/api/v1/Artists', ['json' => ['name' => 'X']]],
            ['POST', '/api/v1/artists.json', ['json' => ['name' => 'X']]],
            ['POST', '/api/v1//artists', ['json' => ['name' => 'X']]],
            ['PUT', '/api/v1/artists/carol-band', ['json' => ['name' => 'X']]],
        ];
        foreach ($variants as [$method, $url, $options]) {
            $status = $this->api($method, $url, $carol, $options)->getStatusCode();
            self::assertContains($status, [403, 404, 405, 415, 422], "$method $url must not succeed (got $status)");
        }

        self::assertCount(1, $this->em()->getRepository(\App\Entity\Artist::class)->findBy(['owner' => $carol->getId()]), 'no artist created by an unverified user');
        self::assertSame('Carol Band', $this->reload($artist)->getName());
    }

    public function testUnverifiedUserCannotVerifyThemselvesThroughProfileUpdate(): void
    {
        $carol = $this->createUser('carol', verified: false);
        foreach (['emailVerified' => true, 'emailVerifiedAt' => '2026-01-01T00:00:00Z', 'roles' => ['ROLE_ADMIN'], 'email' => 'x@example.test', 'username' => 'root', 'id' => '00000000-0000-0000-0000-000000000000', 'suspendedAt' => null, 'password' => 'p4ssw0rd-p4ssw0rd', 'provided' => ['displayName' => true]] as $field => $value) {
            $r = $this->api('PATCH', '/api/v1/me', $carol, ['json' => [$field => $value], 'headers' => ['Content-Type' => 'application/merge-patch+json']]);
            $this->assertProblem($r, 422, 'validation_failed');
        }
        $carol = $this->reload($carol);
        self::assertFalse($carol->isEmailVerified());
        self::assertFalse($carol->isModerator());
        self::assertSame('carol', $carol->getUsername());
    }

    // -------------------------------------------- invisible tracks: 404 ---

    /** @return iterable<string, array{string}> */
    public static function invisibleTracks(): iterable
    {
        yield 'private' => ['private'];
        yield 'processing' => ['processing'];
        yield 'failed' => ['failed'];
        yield 'hidden by moderation' => ['hidden'];
        yield 'private track of a hidden artist' => ['artist-hidden-private'];
    }

    #[DataProvider('invisibleTracks')]
    public function testInvisibleTrackIs404ForOthersOnEveryOperation(string $kind): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->invisibleTrack($alice, $kind);
        $id = $track->getId()->toRfc4122();

        $calls = [
            ['GET', "/api/v1/tracks/$id", []],
            ['GET', "/api/v1/tracks/$id/stream-url", []],
            ['PATCH', "/api/v1/tracks/$id", ['json' => ['title' => 'pwned'], 'headers' => ['Content-Type' => 'application/merge-patch+json']]],
            ['DELETE', "/api/v1/tracks/$id", []],
            ['POST', "/api/v1/tracks/$id/cover", ['extra' => ['files' => ['file' => $this->upload(self::tinyPng(), 'c.png', 'image/png')]]]],
            ['DELETE', "/api/v1/tracks/$id/cover", []],
        ];
        foreach ($calls as [$method, $url, $options]) {
            $this->assertProblem($this->api($method, $url, $bob, $options), 404, 'not_found');
        }
        // Same body as a random id: existence is not leaked.
        $random = $this->api('GET', '/api/v1/tracks/'.\Symfony\Component\Uid\Uuid::v7(), $bob);
        self::assertSame($random->getContent(false), $this->api('GET', "/api/v1/tracks/$id", $bob)->getContent(false));

        // Not listed either.
        $list = $this->json($this->api('GET', '/api/v1/users/alice/tracks', $bob));
        self::assertNotContains($id, array_column($list['items'] ?? [], 'id'));

        // Owner still sees it.
        self::assertSame(200, $this->api('GET', "/api/v1/tracks/$id", $alice)->getStatusCode());
        self::assertSame('Song', $this->reload($track)->getTitle());
    }

    public function testVisibleTrackOfSomeoneElseIs403OnMutation(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $id = $track->getId()->toRfc4122();

        $this->assertProblem($this->api('PATCH', "/api/v1/tracks/$id", $bob, ['json' => ['visibility' => 'private'], 'headers' => ['Content-Type' => 'application/merge-patch+json']]), 403, 'forbidden');
        $this->assertProblem($this->api('DELETE', "/api/v1/tracks/$id", $bob), 403, 'forbidden');
        $this->assertProblem($this->api('POST', "/api/v1/tracks/$id/cover", $bob, ['extra' => ['files' => ['file' => $this->upload(self::tinyPng(), 'c.png', 'image/png')]]]), 403, 'forbidden');
        $this->assertProblem($this->api('DELETE', "/api/v1/tracks/$id/cover", $bob), 403, 'forbidden');
        self::assertSame(Visibility::Public, $this->reload($track)->getVisibility());
    }

    public function testCannotMoveOwnTrackToSomeoneElsesArtistNorUploadUnderIt(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $bobArtist = $this->createArtist($bob, 'Bob Band', 'bob-band');
        $track = $this->createTrack($this->createArtist($alice, 'Alice Band', 'alice-band'));

        $r = $this->api('PATCH', '/api/v1/tracks/'.$track->getId(), $alice, ['json' => ['artistId' => $bobArtist->getId()->toRfc4122()], 'headers' => ['Content-Type' => 'application/merge-patch+json']]);
        $this->assertProblem($r, 404, 'not_found');
        self::assertSame('alice-band', $this->reload($track)->getArtist()->getSlug());

        $r = $this->api('POST', '/api/v1/tracks', $alice, ['json' => [
            'uploadId' => $this->uploadAudio($alice), 'title' => 'Impersonation', 'genreId' => 'pop', 'artistId' => $bobArtist->getId()->toRfc4122(),
        ]]);
        $this->assertProblem($r, 404, 'not_found');
        self::assertCount(0, $this->em()->getRepository(Track::class)->findBy(['artist' => $bobArtist->getId()]));
    }

    public function testArtistIdorAndHiddenArtist(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $this->createArtist($alice, 'Alice Band', 'alice-band');
        $hidden = $this->createArtist($alice, 'Ghost Band', 'ghost-band');
        $hidden->setModeration(Moderation::Hidden);
        $this->em()->flush();
        $merge = ['Content-Type' => 'application/merge-patch+json'];

        $this->assertProblem($this->api('PATCH', '/api/v1/artists/alice-band', $bob, ['json' => ['name' => 'pwned'], 'headers' => $merge]), 403, 'forbidden');
        $this->assertProblem($this->api('DELETE', '/api/v1/artists/alice-band', $bob), 403, 'forbidden');
        $this->assertProblem($this->api('POST', '/api/v1/artists/alice-band/avatar', $bob, ['extra' => ['files' => ['file' => $this->upload(self::tinyPng(), 'a.png', 'image/png')]]]), 403, 'forbidden');
        $this->assertProblem($this->api('DELETE', '/api/v1/artists/alice-band/avatar', $bob), 403, 'forbidden');

        foreach ([['GET', '/api/v1/artists/ghost-band', []], ['GET', '/api/v1/artists/ghost-band/tracks', []], ['PATCH', '/api/v1/artists/ghost-band', ['json' => ['name' => 'x'], 'headers' => $merge]], ['DELETE', '/api/v1/artists/ghost-band', []]] as [$m, $u, $o]) {
            $this->assertProblem($this->api($m, $u, $bob, $o), 404, 'not_found');
        }
        $artists = $this->json($this->api('GET', '/api/v1/users/alice/artists', $bob));
        self::assertNotContains('ghost-band', array_column($artists, 'slug'));
        $this->em()->clear();
        self::assertSame('Alice Band', $this->em()->getRepository(\App\Entity\Artist::class)->findOneBy(['slug' => 'alice-band'])?->getName());
    }

    public function testArtistCreationIgnoresOwnerOrSlugInjection(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        foreach (['owner' => $bob->getId()->toRfc4122(), 'ownerId' => $bob->getId()->toRfc4122(), 'slug' => 'bob-official', 'moderation' => 'visible', 'trackCount' => 999] as $field => $value) {
            $this->assertProblem($this->api('POST', '/api/v1/artists', $alice, ['json' => ['name' => 'Mine', $field => $value]]), 422, 'validation_failed');
        }
    }

    public function testTrackUpdateRejectsServerManagedFields(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice), ready: false);
        foreach (['status' => 'ready', 'audioKey' => 'audio/tracks/other.m4a', 'rawKey' => '../../etc/passwd', 'owner' => 'bob', 'moderation' => 'visible', 'playCount' => 1, 'durationMs' => 1] as $field => $value) {
            $this->assertProblem($this->api('PATCH', '/api/v1/tracks/'.$track->getId(), $alice, ['json' => [$field => $value], 'headers' => ['Content-Type' => 'application/merge-patch+json']]), 422, 'validation_failed');
        }
        self::assertNull($this->reload($track)->getAudioKey());
    }

    public function testEverythingButGenresRequiresAuthentication(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice, 'Alice Band', 'alice-band'));
        foreach (['/api/v1/me', '/api/v1/users/alice', '/api/v1/users/alice/tracks', '/api/v1/artists/alice-band', '/api/v1/tracks/'.$track->getId(), '/api/v1/tracks/'.$track->getId().'/stream-url'] as $url) {
            $this->assertProblem($this->api('GET', $url), 401, 'unauthenticated');
        }
    }

    // -------------------------------------------------------------- helpers ---

    private function invisibleTrack(User $owner, string $kind): Track
    {
        $artist = $this->createArtist($owner);
        $track = match ($kind) {
            'private', 'artist-hidden-private' => $this->createTrack($artist, visibility: Visibility::Private),
            'processing' => $this->createTrack($artist, ready: false),
            'failed' => $this->createTrack($artist, ready: false),
            default => $this->createTrack($artist),
        };
        if ('failed' === $kind) {
            $track->markFailed(FailureReason::InvalidMedia);
        }
        if ('hidden' === $kind) {
            $track->setModeration(Moderation::Hidden);
        }
        if ('artist-hidden-private' === $kind) {
            $artist->setModeration(Moderation::Hidden);
        }
        $this->em()->flush();

        return $track;
    }
}
