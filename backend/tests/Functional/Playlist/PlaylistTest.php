<?php

declare(strict_types=1);

namespace App\Tests\Functional\Playlist;

use App\Entity\Playlist;
use App\Entity\User;
use App\Enum\Moderation;
use App\Enum\Visibility;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** W-20: playlists. */
final class PlaylistTest extends WaveApiTestCase
{
    public function testCrudAndVisibility(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');

        $created = $this->api('POST', '/api/v1/playlists', $alice, ['json' => ['name' => ' Road trip ', 'description' => 'Songs', 'isPublic' => false]]);
        self::assertSame(201, $created->getStatusCode(), $created->getContent(false));
        $p = $this->json($created);
        self::assertSame(['id', 'name', 'description', 'isPublic', 'owner', 'coverUrl', 'itemCount', 'likeCount', 'likedByMe', 'moderation', 'isOwner', 'createdAt', 'updatedAt'], array_keys($p));
        self::assertSame(['Road trip', 'Songs', false, 0, true, null], [$p['name'], $p['description'], $p['isPublic'], $p['itemCount'], $p['isOwner'], $p['coverUrl']]);
        $url = '/api/v1/playlists/'.$p['id'];

        $this->assertProblem($this->api('GET', $url, $bob), 404, 'not_found');
        $this->assertProblem($this->api('PATCH', $url, $bob, ['json' => ['name' => 'x']]), 404, 'not_found');
        self::assertSame([], $this->json($this->api('GET', '/api/v1/users/alice/playlists', $bob))['items']);
        self::assertCount(1, $this->json($this->api('GET', '/api/v1/users/alice/playlists', $alice))['items']);

        $patched = $this->api('PATCH', $url, $alice, ['json' => ['isPublic' => true, 'description' => null]]);
        self::assertSame(200, $patched->getStatusCode());
        self::assertSame([true, null], [$this->json($patched)['isPublic'], $this->json($patched)['description']]);
        self::assertSame(200, $this->api('GET', $url, $bob)->getStatusCode());
        $this->assertProblem($this->api('PATCH', $url, $bob, ['json' => ['name' => 'x']]), 403, 'forbidden');
        $this->assertProblem($this->api('DELETE', $url, $bob), 403, 'forbidden');
        $this->assertProblem($this->api('PATCH', $url, $alice, ['json' => ['name' => null]]), 422, 'validation_failed');
        $this->assertProblem($this->api('PATCH', $url, $alice, ['json' => ['itemCount' => 3]]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', '/api/v1/playlists', $alice, ['json' => ['description' => 'no name']]), 422, 'validation_failed');

        $hidden = $this->createPlaylist($alice, 'Hidden');
        $hidden->setModeration(Moderation::Hidden);
        $this->em()->flush();
        $this->assertProblem($this->api('GET', '/api/v1/playlists/'.$hidden->getId(), $bob), 404, 'not_found');

        self::assertSame(204, $this->api('DELETE', $url, $alice)->getStatusCode());
        $this->assertProblem($this->api('GET', $url, $alice), 404, 'not_found');
    }

    public function testItemsAddRemoveAndContiguousPositions(): void
    {
        $alice = $this->createUser('alice');
        $artist = $this->createArtist($alice);
        $tracks = [];
        foreach (['A', 'B', 'C', 'D'] as $title) {
            $tracks[$title] = $this->createTrack($artist, $title);
        }
        $playlist = $this->createPlaylist($alice);
        foreach ($tracks as $track) {
            $added = $this->add($alice, $playlist, $track->getId()->toRfc4122());
            self::assertSame(201, $added->getStatusCode(), $added->getContent(false));
        }
        self::assertSame(4, $this->json($this->add($alice, $playlist, $this->createTrack($artist, 'E')->getId()->toRfc4122()))['position']);

        self::assertSame(204, $this->api('DELETE', '/api/v1/playlists/'.$playlist->getId().'/items/'.$tracks['B']->getId(), $alice)->getStatusCode());
        $this->assertProblem($this->api('DELETE', '/api/v1/playlists/'.$playlist->getId().'/items/'.$tracks['B']->getId(), $alice), 404, 'not_found');

        $items = $this->json($this->api('GET', '/api/v1/playlists/'.$playlist->getId().'/items', $alice))['items'];
        self::assertSame([0, 1, 2, 3], array_column($items, 'position'));
        self::assertSame(['A', 'C', 'D', 'E'], array_map(static fn (array $i): string => $i['track']['title'], $items));
        self::assertSame(['trackId', 'position', 'addedAt', 'track'], array_keys($items[0]));
        self::assertSame(4, $this->json($this->api('GET', '/api/v1/playlists/'.$playlist->getId(), $alice))['itemCount']);

        $this->assertProblem($this->add($alice, $playlist, $tracks['A']->getId()->toRfc4122()), 409, 'track_already_in_playlist');
        $this->assertProblem($this->add($alice, $playlist, $this->createTrack($artist, 'P', ready: false)->getId()->toRfc4122()), 409, 'track_not_ready');
        $other = $this->createUser('bob');
        $private = $this->createTrack($this->createArtist($other, 'Bob', 'bob-a'), 'Secret', visibility: Visibility::Private);
        $this->assertProblem($this->add($alice, $playlist, $private->getId()->toRfc4122()), 404, 'not_found');
        $this->assertProblem($this->add($other, $playlist, $tracks['A']->getId()->toRfc4122()), 403, 'forbidden');
    }

    public function testReorderRequiresTheExactSet(): void
    {
        $alice = $this->createUser('alice');
        $artist = $this->createArtist($alice);
        $playlist = $this->createPlaylist($alice);
        $ids = [];
        foreach (['A', 'B', 'C'] as $title) {
            $ids[] = $id = $this->createTrack($artist, $title)->getId()->toRfc4122();
            $this->add($alice, $playlist, $id);
        }
        $url = '/api/v1/playlists/'.$playlist->getId().'/order';

        foreach ([[$ids[0], $ids[1]], [...$ids, $this->createTrack($artist, 'X')->getId()->toRfc4122()], [$ids[0], $ids[0], $ids[1]], [$ids[0], $ids[1], $ids[1]]] as $wrong) {
            $this->assertProblem($this->api('PUT', $url, $alice, ['json' => ['trackIds' => $wrong]]), 409, 'playlist_order_mismatch');
        }
        $this->assertProblem($this->api('PUT', $url, $alice, ['json' => ['trackIds' => ['nope']]]), 422, 'validation_failed');

        self::assertSame(204, $this->api('PUT', $url, $alice, ['json' => ['trackIds' => [$ids[2], $ids[0], $ids[1]]]])->getStatusCode());
        $items = $this->json($this->api('GET', '/api/v1/playlists/'.$playlist->getId().'/items', $alice))['items'];
        self::assertSame([$ids[2], $ids[0], $ids[1]], array_column($items, 'trackId'));
        self::assertSame([0, 1, 2], array_column($items, 'position'));
    }

    public function testFiveHundredAndFirstItemIsRefused(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $playlist = $this->createPlaylist($alice);
        $this->em()->getConnection()->executeStatement('UPDATE playlist SET item_count = 500 WHERE id = ?', [$playlist->getId()->toRfc4122()]);

        $this->assertProblem($this->add($alice, $playlist, $track->getId()->toRfc4122()), 409, 'playlist_full');
    }

    public function testUnavailableItemsHaveNullTrackAndCoverFollowsFirstVisibleItem(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $artist = $this->createArtist($alice);
        $first = $this->createTrack($artist, 'First');
        $second = $this->createTrack($artist, 'Second');
        $second = $this->reload($second);
        $second->setCoverKey('images/covers/x/second.webp');
        $this->em()->flush();
        $playlist = $this->createPlaylist($alice);
        $this->add($alice, $playlist, $first->getId()->toRfc4122());
        $this->add($alice, $playlist, $second->getId()->toRfc4122());

        self::assertNull($this->json($this->api('GET', '/api/v1/playlists/'.$playlist->getId(), $bob))['coverUrl'], 'first visible item has no cover');
        $first = $this->reload($first);
        $first->setVisibility(Visibility::Private);
        $this->em()->flush();

        $items = $this->json($this->api('GET', '/api/v1/playlists/'.$playlist->getId().'/items', $bob))['items'];
        // W-26 F15: a non-owner does not get the item at all (no trackId leak), positions keep their gap.
        self::assertSame([[1, 'Second']], array_map(static fn (array $i): array => [$i['position'], $i['track']['title']], $items));
        self::assertStringNotContainsString($first->getId()->toRfc4122(), $this->api('GET', '/api/v1/playlists/'.$playlist->getId().'/items', $bob)->getContent());
        self::assertNotNull($this->json($this->api('GET', '/api/v1/playlists/'.$playlist->getId().'/items', $alice))['items'][0]['track'], 'owner of the track still sees it');
        self::assertSame('http://wave.test/media/images/covers/x/second.webp', $this->json($this->api('GET', '/api/v1/playlists/'.$playlist->getId(), $bob))['coverUrl']);
    }

    public function testItemPaginationByPosition(): void
    {
        $alice = $this->createUser('alice');
        $artist = $this->createArtist($alice);
        $playlist = $this->createPlaylist($alice);
        for ($i = 0; $i < 5; ++$i) {
            $this->add($alice, $playlist, $this->createTrack($artist, 'T'.$i)->getId()->toRfc4122());
        }

        $p1 = $this->json($this->api('GET', '/api/v1/playlists/'.$playlist->getId().'/items?limit=2', $alice));
        $p2 = $this->json($this->api('GET', '/api/v1/playlists/'.$playlist->getId().'/items?limit=2&cursor='.$p1['nextCursor'], $alice));
        $p3 = $this->json($this->api('GET', '/api/v1/playlists/'.$playlist->getId().'/items?limit=2&cursor='.$p2['nextCursor'], $alice));
        self::assertSame([[0, 1], [2, 3], [4]], [array_column($p1['items'], 'position'), array_column($p2['items'], 'position'), array_column($p3['items'], 'position')]);
        self::assertNull($p3['nextCursor']);
        $this->assertProblem($this->api('GET', '/api/v1/playlists/'.$playlist->getId().'/items?limit=101', $alice), 400, 'malformed_request');
    }

    public function testDeletingATrackCompactsEveryPlaylist(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $artist = $this->createArtist($alice);
        [$a, $b, $c] = [$this->createTrack($artist, 'A'), $this->createTrack($artist, 'B'), $this->createTrack($artist, 'C')];
        $playlist = $this->createPlaylist($bob);
        foreach ([$a, $b, $c] as $t) {
            $this->add($bob, $playlist, $t->getId()->toRfc4122());
        }

        self::assertSame(204, $this->api('DELETE', '/api/v1/tracks/'.$b->getId(), $alice)->getStatusCode());

        $items = $this->json($this->api('GET', '/api/v1/playlists/'.$playlist->getId().'/items', $bob))['items'];
        self::assertSame([[0, 'A'], [1, 'C']], array_map(static fn (array $i): array => [$i['position'], $i['track']['title']], $items));
        self::assertSame(2, $this->reload($playlist)->getItemCount());
    }

    private function add(User $as, Playlist $playlist, string $trackId): ResponseInterface
    {
        return $this->api('POST', '/api/v1/playlists/'.$playlist->getId().'/items', $as, ['json' => ['trackId' => $trackId]]);
    }
}
