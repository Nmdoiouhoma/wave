<?php

declare(strict_types=1);

namespace App\Tests\Functional\Track;

use App\Entity\Artist;
use App\Entity\Track;
use App\Enum\Moderation;
use App\Enum\Visibility;
use App\Message\ProcessImage;
use App\Message\ProcessTrack;
use App\Message\PurgeObjects;
use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** W-09: tracks. */
final class TrackTest extends WaveApiTestCase
{
    public function testUploadAutoCreatesArtistStoresQuarantineAndQueuesProcessing(): void
    {
        $alice = $this->createUser('alice', displayName: 'Alice Wonder');

        $response = $this->upload($alice, ['title' => ' First ', 'genreId' => 'lofi', 'visibility' => 'private', 'allowClips' => 'false']);

        self::assertSame(202, $response->getStatusCode(), $response->getContent(false));
        $track = $this->json($response);
        self::assertSame([
            'id', 'title', 'artist', 'owner', 'genre', 'coverUrl', 'durationMs', 'status', 'failureReason', 'visibility',
            'allowClips', 'moderation', 'playCount', 'likeCount', 'likedByMe', 'isOwner', 'canClip', 'createdAt',
        ], array_keys($track));
        self::assertSame('First', $track['title']);
        self::assertSame('processing', $track['status']);
        self::assertNull($track['durationMs']);
        self::assertNull($track['failureReason']);
        self::assertSame('private', $track['visibility']);
        self::assertFalse($track['allowClips']);
        self::assertSame(['id' => 'lofi', 'name' => 'Lo-Fi'], $track['genre']);
        self::assertSame('alice-wonder', $track['artist']['slug']);
        self::assertSame('Alice Wonder', $track['artist']['name']);
        self::assertTrue($track['isOwner']);
        self::assertFalse($track['canClip'], 'not ready yet');
        self::assertSame('/api/v1/tracks/'.$track['id'], $response->getHeaders()['location'][0]);

        $messages = $this->queued('media', ProcessTrack::class);
        self::assertCount(1, $messages);
        self::assertSame($track['id'], $messages[0]->trackId);
        $entity = $this->em()->find(Track::class, $track['id']);
        self::assertNotNull($entity);
        self::assertSame('quarantine/tracks/'.$track['id'].'/source', $entity->getRawKey());
        self::assertSame(2048, $entity->getSizeBytes());
        self::assertTrue(static::getContainer()->get(ObjectStorage::class)->exists(StorageArea::Private, (string) $entity->getRawKey()));

        // Second upload reuses the single artist.
        $second = $this->json($this->upload($alice, ['title' => 'Second', 'genreId' => 'pop']));
        self::assertSame('alice-wonder', $second['artist']['slug']);
        self::assertSame('public', $second['visibility']);
        self::assertTrue($second['allowClips']);
    }

    public function testArtistResolutionRules(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $a1 = $this->createArtist($alice, 'One', 'one');
        $this->createArtist($alice, 'Two', 'two');
        $bobs = $this->createArtist($bob, 'Bobs', 'bobs');

        $this->assertProblem($this->upload($alice, ['title' => 'x', 'genreId' => 'pop']), 422, 'artist_required');
        $this->assertProblem($this->upload($alice, ['title' => 'x', 'genreId' => 'pop', 'artistId' => $bobs->getId()->toRfc4122()]), 404, 'not_found');
        $ok = $this->upload($alice, ['title' => 'x', 'genreId' => 'pop', 'artistId' => $a1->getId()->toRfc4122()]);
        self::assertSame(202, $ok->getStatusCode(), $ok->getContent(false));
        self::assertSame('one', $this->json($ok)['artist']['slug']);
    }

    public function testUploadPreChecks(): void
    {
        $alice = $this->createUser('alice');

        $this->assertProblem($this->upload($alice, ['title' => 'x', 'genreId' => 'pop'], $this->file('song.txt', 'text/plain')), 415, 'unsupported_media_type');
        $this->assertProblem($this->upload($alice, ['title' => 'x', 'genreId' => 'pop'], $this->file('big.mp3', 'audio/mpeg', 31 * 1024 * 1024)), 413, 'payload_too_large');
        $this->assertProblem($this->upload($alice, ['title' => 'x', 'genreId' => 'nope']), 422, 'validation_failed');
        $this->assertProblem($this->upload($alice, ['genreId' => 'pop']), 422, 'validation_failed');
        $this->assertProblem($this->upload($alice, ['title' => 'x', 'genreId' => 'pop', 'visibility' => 'secret']), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', '/api/v1/tracks', $alice, ['extra' => ['parameters' => ['title' => 'x', 'genreId' => 'pop']]]), 422, 'validation_failed');
        self::assertSame(202, $this->upload($alice, ['title' => 'x', 'genreId' => 'pop'], $this->file('song.m4a', 'application/octet-stream'))->getStatusCode());
        self::assertCount(1, $this->queued('media', ProcessTrack::class));
    }

    public function testUploadRequiresVerifiedEmailAndIsRateLimited(): void
    {
        $unverified = $this->createUser('carol', verified: false);
        $this->assertProblem($this->upload($unverified, ['title' => 'x', 'genreId' => 'pop']), 403, 'email_not_verified');

        $alice = $this->createUser('alice');
        for ($i = 0; $i < 10; ++$i) {
            self::assertSame(202, $this->upload($alice, ['title' => 'x'.$i, 'genreId' => 'pop'])->getStatusCode());
        }
        $limited = $this->upload($alice, ['title' => 'x', 'genreId' => 'pop']);
        $this->assertProblem($limited, 429, 'rate_limited');
        self::assertArrayHasKey('retry-after', $limited->getHeaders(false));
    }

    public function testVisibilityRules(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $mod = $this->createUser('mod');
        $mod->setRoles(['ROLE_MODERATOR']);
        $this->em()->flush();
        $artist = $this->createArtist($alice, 'Alice', 'alice-music');
        $public = $this->createTrack($artist, 'Public');
        $private = $this->createTrack($artist, 'Private', visibility: Visibility::Private);
        $processing = $this->createTrack($artist, 'Processing', ready: false);
        $hidden = $this->createTrack($artist, 'Hidden');
        $hidden->setModeration(Moderation::Hidden);
        $this->em()->flush();

        foreach ([$private, $processing, $hidden] as $track) {
            $this->assertProblem($this->api('GET', '/api/v1/tracks/'.$track->getId(), $bob), 404, 'not_found');
            self::assertSame(200, $this->api('GET', '/api/v1/tracks/'.$track->getId(), $alice)->getStatusCode());
            self::assertSame(200, $this->api('GET', '/api/v1/tracks/'.$track->getId(), $mod)->getStatusCode());
        }
        $seen = $this->json($this->api('GET', '/api/v1/tracks/'.$public->getId(), $bob));
        self::assertFalse($seen['isOwner']);
        self::assertTrue($seen['canClip']);
        self::assertSame(180000, $seen['durationMs']);

        self::assertSame(['Public'], array_column($this->json($this->api('GET', '/api/v1/users/alice/tracks', $bob))['items'], 'title'));
        self::assertSame(['Public'], array_column($this->json($this->api('GET', '/api/v1/artists/alice-music/tracks', $bob))['items'], 'title'));
        self::assertSame(['Hidden', 'Processing', 'Private', 'Public'], array_column($this->json($this->api('GET', '/api/v1/users/alice/tracks', $alice))['items'], 'title'));
        self::assertCount(4, $this->json($this->api('GET', '/api/v1/artists/alice-music/tracks', $alice))['items']);

        $this->assertProblem($this->api('GET', '/api/v1/tracks/not-a-uuid', $bob), 404, 'not_found');
        $this->assertProblem($this->api('GET', '/api/v1/users/nobody/tracks', $bob), 404, 'not_found');
        // artist.trackCount counts public, ready, visible tracks only.
        self::assertSame(1, $this->json($this->api('GET', '/api/v1/artists/alice-music', $bob))['trackCount']);
    }

    public function testCursorPagination(): void
    {
        $alice = $this->createUser('alice');
        $artist = $this->createArtist($alice);
        for ($i = 1; $i <= 5; ++$i) {
            $this->createTrack($artist, 'T'.$i);
        }

        $page1 = $this->json($this->api('GET', '/api/v1/users/alice/tracks?limit=2', $alice));
        self::assertSame(['T5', 'T4'], array_column($page1['items'], 'title'));
        self::assertIsString($page1['nextCursor']);
        $page2 = $this->json($this->api('GET', '/api/v1/users/alice/tracks?limit=2&cursor='.$page1['nextCursor'], $alice));
        self::assertSame(['T3', 'T2'], array_column($page2['items'], 'title'));
        $page3 = $this->json($this->api('GET', '/api/v1/users/alice/tracks?limit=2&cursor='.$page2['nextCursor'], $alice));
        self::assertSame(['T1'], array_column($page3['items'], 'title'));
        self::assertNull($page3['nextCursor']);

        $tampered = substr($page1['nextCursor'], 0, -2).('AA' === substr($page1['nextCursor'], -2) ? 'BB' : 'AA');
        $this->assertProblem($this->api('GET', '/api/v1/users/alice/tracks?cursor='.$tampered, $alice), 400, 'invalid_cursor');
        $this->assertProblem($this->api('GET', '/api/v1/users/alice/tracks?limit=51', $alice), 400, 'malformed_request');
        $this->assertProblem($this->api('GET', '/api/v1/users/alice/tracks?limit=0', $alice), 400, 'malformed_request');
    }

    public function testPatchByOwnerOnly(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $artist = $this->createArtist($alice, 'A1', 'a1');
        $other = $this->createArtist($alice, 'A2', 'a2');
        $bobs = $this->createArtist($bob, 'B', 'b');
        $track = $this->createTrack($artist);
        $url = '/api/v1/tracks/'.$track->getId();

        $response = $this->api('PATCH', $url, $alice, ['json' => ['title' => 'Renamed', 'genreId' => 'jazz', 'visibility' => 'private', 'allowClips' => false, 'artistId' => $other->getId()->toRfc4122()]]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $body = $this->json($response);
        self::assertSame(['Renamed', 'jazz', 'private', false, 'a2'], [$body['title'], $body['genre']['id'], $body['visibility'], $body['allowClips'], $body['artist']['slug']]);
        self::assertSame(0, $this->reload($artist)->getTrackCount());

        $this->assertProblem($this->api('PATCH', $url, $alice, ['json' => ['artistId' => $bobs->getId()->toRfc4122()]]), 404, 'not_found');
        $this->assertProblem($this->api('PATCH', $url, $alice, ['json' => ['status' => 'ready']]), 422, 'validation_failed');
        $this->assertProblem($this->api('PATCH', $url, $alice, ['json' => ['title' => null]]), 422, 'validation_failed');
        // Private now: bob cannot see it at all.
        $this->assertProblem($this->api('PATCH', $url, $bob, ['json' => ['title' => 'x']]), 404, 'not_found');
        $this->api('PATCH', $url, $alice, ['json' => ['visibility' => 'public']]);
        $this->assertProblem($this->api('PATCH', $url, $bob, ['json' => ['title' => 'x']]), 403, 'forbidden');
        $this->assertProblem($this->api('DELETE', $url, $bob), 403, 'forbidden');
        self::assertSame(1, $this->reload($other)->getTrackCount());
    }

    public function testDeletePurgesObjects(): void
    {
        $alice = $this->createUser('alice');
        $artist = $this->createArtist($alice);
        $track = $this->createTrack($artist);
        $track = $this->reload($track);
        $track->setCoverKey('images/covers/'.$track->getId().'/c.webp');
        $this->em()->flush();
        $id = $track->getId()->toRfc4122();

        self::assertSame(204, $this->api('DELETE', '/api/v1/tracks/'.$id, $alice)->getStatusCode());

        self::assertNull($this->em()->find(Track::class, $id));
        $purges = $this->queued('async', PurgeObjects::class);
        self::assertSame(['public' => ['images/covers/'.$id.'/c.webp']], array_column(array_map(static fn (PurgeObjects $p): array => ['a' => $p->area, 'k' => $p->keys], array_filter($purges, static fn (PurgeObjects $p): bool => 'public' === $p->area)), 'k', 'a'));
        $private = array_values(array_filter($purges, static fn (PurgeObjects $p): bool => 'private' === $p->area));
        self::assertSame(['audio/tracks/'.$id.'.m4a'], $private[0]->keys, 'raw key was nulled by markReady');
        $this->assertProblem($this->api('GET', '/api/v1/tracks/'.$id, $alice), 404, 'not_found');
        self::assertSame(0, $this->reload($artist)->getTrackCount());
    }

    public function testCoverUploadAndDelete(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));

        $response = $this->api('POST', '/api/v1/tracks/'.$track->getId().'/cover', $alice, ['extra' => ['files' => ['file' => $this->file('c.jpg', 'image/jpeg')]]]);
        self::assertSame(202, $response->getStatusCode(), $response->getContent(false));
        $msg = $this->queued('media', ProcessImage::class)[0];
        self::assertSame(['track_cover', $track->getId()->toRfc4122()], [$msg->target, $msg->targetId]);
        self::assertStringStartsWith('quarantine/covers/'.$track->getId().'/', $msg->quarantineKey);

        self::assertSame(204, $this->api('DELETE', '/api/v1/tracks/'.$track->getId().'/cover', $alice)->getStatusCode());
        self::assertCount(0, $this->queued('async', PurgeObjects::class), 'no cover → nothing to purge');
    }

    public function testCoverUploadRequiresVerifiedEmail(): void
    {
        $carol = $this->createUser('carol', verified: false);
        $track = $this->createTrack($this->createArtist($carol));

        $this->assertProblem($this->api('POST', '/api/v1/tracks/'.$track->getId().'/cover', $carol, ['extra' => ['files' => ['file' => $this->file('c.jpg', 'image/jpeg')]]]), 403, 'email_not_verified');
        self::assertCount(0, $this->queued('media'));
    }

    public function testMarkReadyFromMediaHandlerRecountsArtist(): void
    {
        $alice = $this->createUser('alice');
        $artist = $this->createArtist($alice);
        $track = $this->createTrack($artist, ready: false);
        self::assertSame(0, $this->reload($artist)->getTrackCount());

        $track = $this->reload($track);
        $track->markReady('audio/tracks/x.m4a', 1000);
        $this->em()->flush();

        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT track_count FROM artist WHERE id = ?', [$artist->getId()->toRfc4122()]));
        self::assertSame(1, $this->em()->find(Artist::class, $artist->getId())?->getTrackCount());
    }

    /** @param array<string, string> $fields */
    private function upload(\App\Entity\User $as, array $fields, ?UploadedFile $file = null): ResponseInterface
    {
        return $this->api('POST', '/api/v1/tracks', $as, ['extra' => ['parameters' => $fields, 'files' => ['file' => $file ?? $this->file('song.mp3', 'audio/mpeg')]]]);
    }

    /** @param int<0, max> $size */
    private function file(string $name, string $mime, int $size = 2048): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wave-test-');
        self::assertIsString($path);
        $handle = fopen($path, 'w');
        self::assertIsResource($handle);
        ftruncate($handle, $size);
        fclose($handle);

        return new UploadedFile($path, $name, $mime, null, true);
    }
}
