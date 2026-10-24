<?php

declare(strict_types=1);

namespace App\Tests\Functional\Artist;

use App\Entity\Artist;
use App\Enum\Moderation;
use App\Message\ProcessImage;
use App\Message\PurgeObjects;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** W-08: artists. */
final class ArtistTest extends WaveApiTestCase
{
    public function testCreateGetAndList(): void
    {
        $alice = $this->createUser('alice');

        $response = $this->api('POST', '/api/v1/artists', $alice, ['json' => ['name' => '  Beyoncé ', 'bio' => 'Queen']]);

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $artist = $this->json($response);
        self::assertSame(['id', 'slug', 'name', 'avatarUrl', 'bio', 'trackCount', 'owner', 'moderation', 'isOwner', 'createdAt'], array_keys($artist));
        self::assertSame('beyonce', $artist['slug']);
        self::assertSame('Beyoncé', $artist['name']);
        self::assertSame(0, $artist['trackCount']);
        self::assertSame('visible', $artist['moderation']);
        self::assertTrue($artist['isOwner']);
        self::assertSame(['id' => $alice->getId()->toRfc4122(), 'username' => 'alice', 'displayName' => 'Alice', 'avatarUrl' => null], $artist['owner']);

        $bob = $this->createUser('bob');
        $seen = $this->json($this->api('GET', '/api/v1/artists/beyonce', $bob));
        self::assertFalse($seen['isOwner']);
        self::assertSame('Beyoncé', $seen['name']);

        $second = $this->json($this->api('POST', '/api/v1/artists', $alice, ['json' => ['name' => 'Beyonce']]));
        self::assertSame('beyonce-2', $second['slug']);
        self::assertSame('beyonce-3', $this->json($this->api('POST', '/api/v1/artists', $bob, ['json' => ['name' => 'BEYONCÉ!']]))['slug']);

        $list = $this->json($this->api('GET', '/api/v1/users/alice/artists', $bob));
        self::assertSame(['beyonce', 'beyonce-2'], array_column($list, 'slug'), 'oldest first, plain array');
        $this->assertProblem($this->api('GET', '/api/v1/users/nobody/artists', $bob), 404, 'not_found');
        $this->assertProblem($this->api('GET', '/api/v1/artists/nope', $bob), 404, 'not_found');
    }

    public function testRenameKeepsSlugAndOnlyOwnerMayPatch(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $this->api('POST', '/api/v1/artists', $alice, ['json' => ['name' => 'Daft Punk']]);

        $renamed = $this->api('PATCH', '/api/v1/artists/daft-punk', $alice, ['json' => ['name' => 'Daft Punk Live', 'bio' => null]]);
        self::assertSame(200, $renamed->getStatusCode(), $renamed->getContent(false));
        self::assertSame('daft-punk', $this->json($renamed)['slug']);
        self::assertSame('Daft Punk Live', $this->json($renamed)['name']);

        $this->assertProblem($this->api('PATCH', '/api/v1/artists/daft-punk', $bob, ['json' => ['name' => 'Mine']]), 403, 'forbidden');
        $this->assertProblem($this->api('PATCH', '/api/v1/artists/daft-punk', $alice, ['json' => ['slug' => 'other']]), 422, 'validation_failed');
        $this->assertProblem($this->api('PATCH', '/api/v1/artists/daft-punk', $alice, ['json' => ['name' => '']]), 422, 'validation_failed');
        $this->assertProblem($this->api('DELETE', '/api/v1/artists/daft-punk', $bob), 403, 'forbidden');
    }

    public function testLimitOfTenArtists(): void
    {
        $alice = $this->createUser('alice');
        for ($i = 1; $i <= 10; ++$i) {
            self::assertSame(201, $this->api('POST', '/api/v1/artists', $alice, ['json' => ['name' => 'Band '.$i]])->getStatusCode());
        }

        $this->assertProblem($this->api('POST', '/api/v1/artists', $alice, ['json' => ['name' => 'Band 11']]), 409, 'artist_limit_reached');
        $this->assertProblem($this->api('POST', '/api/v1/artists', $alice, ['json' => []]), 422, 'validation_failed');
    }

    public function testDeleteWithTracksConflictsAndWithoutTracksSucceeds(): void
    {
        $alice = $this->createUser('alice');
        $artist = $this->createArtist($alice, 'With Tracks', 'with-tracks');
        $this->createTrack($artist, ready: false);
        $this->createArtist($alice, 'Empty', 'empty');

        $this->assertProblem($this->api('DELETE', '/api/v1/artists/with-tracks', $alice), 409, 'artist_has_tracks');
        self::assertSame(204, $this->api('DELETE', '/api/v1/artists/empty', $alice)->getStatusCode());
        $this->assertProblem($this->api('GET', '/api/v1/artists/empty', $alice), 404, 'not_found');
    }

    public function testHiddenArtistIsNotFoundForOthers(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $artist = $this->createArtist($alice, 'Hidden', 'hidden');
        $artist->setModeration(Moderation::Hidden);
        $this->em()->flush();

        $this->assertProblem($this->api('GET', '/api/v1/artists/hidden', $bob), 404, 'not_found');
        $this->assertProblem($this->api('PATCH', '/api/v1/artists/hidden', $bob, ['json' => ['name' => 'x']]), 404, 'not_found');
        self::assertSame([], $this->json($this->api('GET', '/api/v1/users/alice/artists', $bob)));
        self::assertSame('hidden', $this->json($this->api('GET', '/api/v1/artists/hidden', $alice))['moderation']);
        self::assertCount(1, $this->json($this->api('GET', '/api/v1/users/alice/artists', $alice)));
    }

    public function testAvatarUploadAndDelete(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $artist = $this->createArtist($alice, 'Pics', 'pics');

        $response = $this->api('POST', '/api/v1/artists/pics/avatar', $alice, ['extra' => ['files' => ['file' => $this->image()]]]);
        self::assertSame(202, $response->getStatusCode(), $response->getContent(false));
        $messages = $this->queued('media', ProcessImage::class);
        self::assertCount(1, $messages);
        self::assertSame('artist_avatar', $messages[0]->target);
        self::assertSame($artist->getId()->toRfc4122(), $messages[0]->targetId);
        self::assertStringStartsWith('quarantine/avatars/artists/'.$artist->getId()->toRfc4122().'/', $messages[0]->quarantineKey);

        $this->assertProblem($this->api('POST', '/api/v1/artists/pics/avatar', $bob, ['extra' => ['files' => ['file' => $this->image()]]]), 403, 'forbidden');

        $artist = $this->reload($artist);
        $artist->setAvatarKey('images/avatars/artists/x/y.webp');
        $this->em()->flush();
        self::assertSame(204, $this->api('DELETE', '/api/v1/artists/pics/avatar', $alice)->getStatusCode());
        self::assertSame([['images/avatars/artists/x/y.webp']], array_map(static fn (PurgeObjects $m): array => $m->keys, $this->queued('async', PurgeObjects::class)));
        self::assertNull($this->reload($artist)->getAvatarKey());
    }

    public function testUnverifiedUserIsBlockedOnGatedArtistOperations(): void
    {
        $alice = $this->createUser('alice', verified: false);
        $this->createArtist($alice, 'Mine', 'mine');

        $this->assertProblem($this->api('POST', '/api/v1/artists', $alice, ['json' => ['name' => 'X']]), 403, 'email_not_verified');
        $this->assertProblem($this->api('PATCH', '/api/v1/artists/mine', $alice, ['json' => ['name' => 'X']]), 403, 'email_not_verified');
        $this->assertProblem($this->api('POST', '/api/v1/artists/mine/avatar', $alice, ['extra' => ['files' => ['file' => $this->image()]]]), 403, 'email_not_verified');
        self::assertCount(1, $this->em()->getRepository(Artist::class)->findAll());
    }

    private function image(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wave-test-');
        self::assertIsString($path);
        file_put_contents($path, 'not really a png');

        return new UploadedFile($path, 'a.png', 'image/png', null, true);
    }
}
