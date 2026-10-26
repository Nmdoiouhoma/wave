<?php

declare(strict_types=1);

namespace App\Tests\Functional\Track;

use App\Entity\Track;
use App\Entity\TrackUpload;
use App\Entity\User;
use App\Enum\TrackUploadStatus;
use App\Message\ProcessTrack;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** W-60 (ADR 009): presigned upload slot, then JSON `POST /tracks` consuming it. */
final class TrackUploadTest extends WaveApiTestCase
{
    public function testSlotCarriesAPresignedPostPolicy(): void
    {
        $alice = $this->createUser('alice');

        $response = $this->slot($alice, 'clip.mp4', 200 * 1024 * 1024, 'video/mp4');

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $slot = $this->json($response);
        self::assertSame(['uploadId', 'method', 'url', 'fields', 'maxBytes', 'expiresAt'], array_keys($slot));
        self::assertSame('POST', $slot['method']);
        self::assertSame('http://wave.test/upload/wave-private', $slot['url']);
        self::assertSame(209715200, $slot['maxBytes']);
        $key = 'quarantine/uploads/'.$alice->getId().'/'.$slot['uploadId'];
        self::assertSame($key, $slot['fields']['key']);
        foreach (['Policy', 'X-Amz-Signature', 'X-Amz-Credential', 'X-Amz-Algorithm', 'X-Amz-Date'] as $field) {
            self::assertArrayHasKey($field, $slot['fields']);
        }
        $policy = json_decode((string) base64_decode($slot['fields']['Policy']), true);
        self::assertIsArray($policy);
        self::assertContains(['eq', '$key', $key], $policy['conditions']);
        self::assertContains(['content-length-range', 1, 209715200], $policy['conditions']);
        $expires = new \DateTimeImmutable($slot['expiresAt']);
        self::assertEqualsWithDelta(time() + 900, $expires->getTimestamp(), 3);
        self::assertEqualsWithDelta($expires->getTimestamp(), new \DateTimeImmutable($policy['expiration'])->getTimestamp(), 1);

        $row = $this->em()->find(TrackUpload::class, $slot['uploadId']);
        self::assertNotNull($row);
        self::assertSame(TrackUploadStatus::Pending, $row->getStatus());
        self::assertSame(['clip.mp4', 209715200, 'video/mp4'], [$row->getDeclaredFilename(), $row->getDeclaredSize(), $row->getDeclaredType()]);
    }

    public function testDeclaredSizeAndTypeLimits(): void
    {
        $alice = $this->createUser('alice');

        self::assertSame(31457280, $this->json($this->slot($alice, 'a.mp3', 31457280, 'audio/mpeg'))['maxBytes']);
        $this->assertProblem($this->slot($alice, 'a.mp3', 31 * 1024 * 1024, 'audio/mpeg'), 413, 'payload_too_large');
        $this->assertProblem($this->slot($alice, 'a.mp4', 209715201, 'video/mp4'), 413, 'payload_too_large');
        $this->assertProblem($this->slot($alice, 'a.wav', 1000, 'audio/wav'), 415, 'unsupported_media_type');
        $this->assertProblem($this->slot($alice, 'a.mp3', 1000, 'text/plain'), 415, 'unsupported_media_type');
        $this->assertProblem($this->slot($alice, 'noextension', 1000, 'audio/mpeg'), 415, 'unsupported_media_type');
        $this->assertProblem($this->slot($alice, 'a.mp3', 0, 'audio/mpeg'), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', '/api/v1/tracks/uploads', $alice, ['json' => ['filename' => 'a.mp3', 'size' => 1, 'contentType' => 'audio/mpeg', 'key' => 'x']]), 422, 'validation_failed');
    }

    public function testElevenPendingUploadsAreRefused(): void
    {
        $alice = $this->createUser('alice');
        for ($i = 0; $i < 10; ++$i) {
            self::assertSame(201, $this->slot($alice)->getStatusCode());
        }

        $this->assertProblem($this->slot($alice), 409, 'too_many_pending_uploads');
        self::assertSame(201, $this->slot($this->createUser('bob'))->getStatusCode(), 'per user');
    }

    public function testSlotsRequireVerifiedEmailAndAreRateLimited(): void
    {
        $this->assertProblem($this->slot($this->createUser('carol', verified: false)), 403, 'email_not_verified');

        $alice = $this->createUser('alice');
        $this->em()->getConnection()->executeStatement("INSERT INTO track_upload (id, user_id, quarantine_key, declared_filename, declared_size, declared_type, max_bytes, status, expires_at, created_at)
            SELECT gen_random_uuid(), :u, 'k'||g, 'x.mp3', 1, 'audio/mpeg', 1, 'consumed', now(), now() FROM generate_series(1, 1) g", ['u' => $alice->getId()->toRfc4122()]);
        for ($i = 0; $i < 30; ++$i) {
            $this->slot($alice);
            $this->em()->getConnection()->executeStatement("UPDATE track_upload SET status = 'consumed'");
        }
        $limited = $this->slot($alice);
        $this->assertProblem($limited, 429, 'rate_limited');
        self::assertArrayHasKey('retry-after', $limited->getHeaders(false));
    }

    public function testCreateTrackConsumesTheUpload(): void
    {
        $alice = $this->createUser('alice', displayName: 'Alice Wonder');
        $uploadId = $this->uploadAudio($alice, 'clip.mp4', 4096, 'video/mp4');

        $response = $this->create($alice, ['uploadId' => $uploadId, 'title' => ' First ', 'genreId' => 'lofi', 'visibility' => 'private', 'allowClips' => false]);

        self::assertSame(202, $response->getStatusCode(), $response->getContent(false));
        $track = $this->json($response);
        self::assertSame(['First', 'processing', 'private', false, 'alice-wonder'], [$track['title'], $track['status'], $track['visibility'], $track['allowClips'], $track['artist']['slug']]);
        self::assertSame('/api/v1/tracks/'.$track['id'], $response->getHeaders()['location'][0]);
        self::assertSame([$track['id']], array_map(static fn (ProcessTrack $m): string => $m->trackId, $this->queued('media', ProcessTrack::class)));

        $entity = $this->em()->find(Track::class, $track['id']);
        self::assertNotNull($entity);
        self::assertSame('quarantine/uploads/'.$alice->getId().'/'.$uploadId, $entity->getRawKey());
        self::assertSame(4096, $entity->getSizeBytes(), 'real object size, not the declared one');
        $this->em()->clear();
        $upload = $this->em()->find(TrackUpload::class, $uploadId);
        self::assertSame(TrackUploadStatus::Consumed, $upload?->getStatus());
        self::assertSame($track['id'], $upload->getTrack()?->getId()->toRfc4122());

        $this->assertProblem($this->create($alice, ['uploadId' => $uploadId, 'title' => 'Again', 'genreId' => 'pop']), 409, 'upload_already_used');
        self::assertCount(1, $this->queued('media', ProcessTrack::class));
    }

    public function testUploadChecks(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $bobsUpload = $this->uploadAudio($bob);

        $this->assertProblem($this->create($alice, ['uploadId' => $bobsUpload, 'title' => 'x', 'genreId' => 'pop']), 404, 'not_found');
        $this->assertProblem($this->create($alice, ['uploadId' => '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e', 'title' => 'x', 'genreId' => 'pop']), 404, 'not_found');

        $notSent = $this->json($this->slot($alice))['uploadId'];
        $this->assertProblem($this->create($alice, ['uploadId' => $notSent, 'title' => 'x', 'genreId' => 'pop']), 409, 'upload_incomplete');

        $old = $this->uploadAudio($alice);
        $this->em()->getConnection()->executeStatement("UPDATE track_upload SET created_at = now() - interval '121 minutes' WHERE id = ?", [$old]);
        $this->assertProblem($this->create($alice, ['uploadId' => $old, 'title' => 'x', 'genreId' => 'pop']), 409, 'upload_expired');
        $expired = $this->uploadAudio($alice);
        $this->em()->getConnection()->executeStatement("UPDATE track_upload SET status = 'expired' WHERE id = ?", [$expired]);
        $this->assertProblem($this->create($alice, ['uploadId' => $expired, 'title' => 'x', 'genreId' => 'pop']), 409, 'upload_expired');

        $ok = $this->uploadAudio($alice);
        $this->assertProblem($this->create($alice, ['uploadId' => $ok, 'title' => 'x', 'genreId' => 'nope']), 422, 'validation_failed');
        $this->assertProblem($this->create($alice, ['uploadId' => $ok, 'genreId' => 'pop']), 422, 'validation_failed');
        $this->assertProblem($this->create($alice, ['uploadId' => 'nope', 'title' => 'x', 'genreId' => 'pop']), 422, 'validation_failed');
        $this->assertProblem($this->create($alice, ['uploadId' => $ok, 'title' => 'x', 'genreId' => 'pop', 'visibility' => 'secret']), 422, 'validation_failed');
        self::assertSame(202, $this->create($alice, ['uploadId' => $ok, 'title' => 'x', 'genreId' => 'pop'])->getStatusCode(), 'failed attempts do not consume');
    }

    public function testArtistResolutionRules(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $a1 = $this->createArtist($alice, 'One', 'one');
        $this->createArtist($alice, 'Two', 'two');
        $bobs = $this->createArtist($bob, 'Bobs', 'bobs');
        $upload = $this->uploadAudio($alice);

        $this->assertProblem($this->create($alice, ['uploadId' => $upload, 'title' => 'x', 'genreId' => 'pop']), 422, 'artist_required');
        $this->assertProblem($this->create($alice, ['uploadId' => $upload, 'title' => 'x', 'genreId' => 'pop', 'artistId' => $bobs->getId()->toRfc4122()]), 404, 'not_found');
        $ok = $this->create($alice, ['uploadId' => $upload, 'title' => 'x', 'genreId' => 'pop', 'artistId' => $a1->getId()->toRfc4122()]);
        self::assertSame(202, $ok->getStatusCode(), $ok->getContent(false));
        self::assertSame('one', $this->json($ok)['artist']['slug']);
    }

    public function testCreateRequiresVerifiedEmailAndMultipartIsGone(): void
    {
        $carol = $this->createUser('carol', verified: false);
        $this->assertProblem($this->create($carol, ['uploadId' => '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e', 'title' => 'x', 'genreId' => 'pop']), 403, 'email_not_verified');

        $alice = $this->createUser('alice');
        $multipart = $this->api('POST', '/api/v1/tracks', $alice, ['extra' => ['parameters' => ['title' => 'x', 'genreId' => 'pop']]]);
        self::assertContains($multipart->getStatusCode(), [400, 415], 'multipart POST /tracks no longer exists');
    }

    public function testConcurrentConsumeIsSingleUse(): void
    {
        $alice = $this->createUser('alice');
        $this->createArtist($alice);
        $upload = $this->uploadAudio($alice);
        $repo = static::getContainer()->get(\App\Repository\TrackUploadRepository::class);
        $track = $this->createTrack($this->em()->getRepository(\App\Entity\Artist::class)->findAll()[0]);
        $now = new \DateTimeImmutable();

        self::assertTrue($repo->consume(\Symfony\Component\Uid\Uuid::fromString($upload), $alice, $track->getId(), $now));
        self::assertFalse($repo->consume(\Symfony\Component\Uid\Uuid::fromString($upload), $alice, $track->getId(), $now));
    }

    private function slot(User $as, string $filename = 'song.mp3', int $size = 2048, string $contentType = 'audio/mpeg'): ResponseInterface
    {
        return $this->api('POST', '/api/v1/tracks/uploads', $as, ['json' => ['filename' => $filename, 'size' => $size, 'contentType' => $contentType]]);
    }

    /** @param array<string, mixed> $body */
    private function create(User $as, array $body): ResponseInterface
    {
        return $this->api('POST', '/api/v1/tracks', $as, ['json' => $body]);
    }
}
