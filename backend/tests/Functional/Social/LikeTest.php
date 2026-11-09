<?php

declare(strict_types=1);

namespace App\Tests\Functional\Social;

use App\Enum\Visibility;
use App\Message\RecomputeAffinity;
use App\Tests\Functional\WaveApiTestCase;

/** W-22: likes. */
final class LikeTest extends WaveApiTestCase
{
    public function testTrackLikeIsIdempotentAndReflectedEverywhere(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $url = '/api/v1/tracks/'.$track->getId().'/like';

        self::assertSame(['liked' => true, 'likeCount' => 1], $this->json($this->api('PUT', $url, $bob)));
        self::assertSame(['liked' => true, 'likeCount' => 1], $this->json($this->api('PUT', $url, $bob)));
        self::assertSame(['liked' => true, 'likeCount' => 2], $this->json($this->api('PUT', $url, $alice)), 'liking own content is allowed');

        self::assertTrue($this->json($this->api('GET', '/api/v1/tracks/'.$track->getId(), $bob))['likedByMe']);
        self::assertSame([true], array_column($this->json($this->api('GET', '/api/v1/users/alice/tracks', $bob))['items'], 'likedByMe'));
        self::assertSame(2, $this->json($this->api('GET', '/api/v1/tracks/'.$track->getId(), $bob))['likeCount']);
        self::assertSame(
            [$bob->getId()->toRfc4122(), $alice->getId()->toRfc4122()],
            array_map(static fn (RecomputeAffinity $m): string => $m->userId, $this->queued('async', RecomputeAffinity::class)),
            'one per user: repeated like is not new, and requests are deduplicated',
        );

        self::assertSame(['liked' => false, 'likeCount' => 1], $this->json($this->api('DELETE', $url, $bob)));
        self::assertSame(['liked' => false, 'likeCount' => 1], $this->json($this->api('DELETE', $url, $bob)));
        self::assertFalse($this->json($this->api('GET', '/api/v1/tracks/'.$track->getId(), $bob))['likedByMe']);

        $private = $this->createTrack($this->createArtist($alice, 'P', 'p'), 'Secret', visibility: Visibility::Private);
        $this->assertProblem($this->api('PUT', '/api/v1/tracks/'.$private->getId().'/like', $bob), 404, 'not_found');
    }

    /**
     * FB-13: the affinity deduplication lock (DoctrineDbalStore, same connection) failing must not
     * fail the like nor poison its transaction (25P02). Any INSERT into lock_keys fails here.
     */
    public function testLikeSucceedsWhenTheAffinityLockFails(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $other = $this->createTrack($this->createArtist($alice, 'Other', 'other'), 'Other song');
        $db = $this->em()->getConnection();
        $db->executeStatement('ALTER TABLE lock_keys ADD CONSTRAINT fb13_lock_fails CHECK (false) NOT VALID');

        self::assertSame(['liked' => true, 'likeCount' => 1], $this->json($this->api('PUT', '/api/v1/tracks/'.$track->getId().'/like', $bob)));
        self::assertSame(['liked' => true, 'likeCount' => 1], $this->json($this->api('PUT', '/api/v1/tracks/'.$other->getId().'/like', $bob)), 'connection still usable');
        self::assertSame(1, $this->json($this->api('GET', '/api/v1/tracks/'.$track->getId(), $bob))['likeCount']);
        self::assertSame(2, (int) $db->fetchOne('SELECT count(*) FROM track_like WHERE user_id = ?', [$bob->getId()->toRfc4122()]));
        self::assertSame([], $this->queued('async', RecomputeAffinity::class), 'recompute request dropped, not half-sent');
    }

    public function testPlaylistLikeAndMyLikedLists(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $artist = $this->createArtist($alice);
        [$t1, $t2] = [$this->createTrack($artist, 'One'), $this->createTrack($artist, 'Two')];
        $this->api('PUT', '/api/v1/tracks/'.$t1->getId().'/like', $bob);
        $this->api('PUT', '/api/v1/tracks/'.$t2->getId().'/like', $bob);
        $open = $this->createPlaylist($alice, 'Open');
        $closing = $this->createPlaylist($alice, 'Closing');
        foreach ([$open, $closing] as $p) {
            self::assertSame(['liked' => true, 'likeCount' => 1], $this->json($this->api('PUT', '/api/v1/playlists/'.$p->getId().'/like', $bob)));
        }

        $tracks = $this->json($this->api('GET', '/api/v1/me/likes/tracks?limit=1', $bob));
        self::assertSame(['Two'], array_column($tracks['items'], 'title'));
        self::assertSame(['One'], array_column($this->json($this->api('GET', '/api/v1/me/likes/tracks?cursor='.$tracks['nextCursor'], $bob))['items'], 'title'));
        self::assertSame(['Closing', 'Open'], array_column($this->json($this->api('GET', '/api/v1/me/likes/playlists', $bob))['items'], 'name'));
        self::assertTrue($this->json($this->api('GET', '/api/v1/playlists/'.$open->getId(), $bob))['likedByMe']);

        // Made private / hidden → omitted from the liker's lists.
        $this->em()->getConnection()->executeStatement('UPDATE playlist SET is_public = false WHERE id = ?', [$closing->getId()->toRfc4122()]);
        $this->em()->getConnection()->executeStatement("UPDATE track SET visibility = 'private' WHERE id = ?", [$t2->getId()->toRfc4122()]);
        self::assertSame(['Open'], array_column($this->json($this->api('GET', '/api/v1/me/likes/playlists', $bob))['items'], 'name'));
        self::assertSame(['One'], array_column($this->json($this->api('GET', '/api/v1/me/likes/tracks', $bob))['items'], 'title'));
        $this->assertProblem($this->api('PUT', '/api/v1/playlists/'.$closing->getId().'/like', $bob), 404, 'not_found');
    }

    public function testLikedByMeHasNoNPlusOne(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $artist = $this->createArtist($alice);
        $playlist = $this->createPlaylist($alice);
        for ($i = 0; $i < 6; ++$i) {
            $t = $this->createTrack($artist, 'T'.$i);
            $this->api('PUT', '/api/v1/tracks/'.$t->getId().'/like', $bob);
            $this->api('POST', '/api/v1/playlists/'.$playlist->getId().'/items', $alice, ['json' => ['trackId' => $t->getId()->toRfc4122()]]);
            $this->api('PUT', '/api/v1/playlists/'.$this->createPlaylist($alice, 'P'.$i)->getId().'/like', $bob);
        }

        foreach (['/api/v1/users/alice/tracks', '/api/v1/me/likes/tracks', '/api/v1/playlists/'.$playlist->getId().'/items', '/api/v1/me/likes/playlists', '/api/v1/users/alice/playlists'] as $url) {
            $two = $this->countQueries('GET', $url.'?limit=2', $bob);
            $six = $this->countQueries('GET', $url.'?limit=6', $bob);
            self::assertSame($two, $six, $url.': query count must not grow with the page size');
        }
        self::assertCount(6, array_filter(array_column($this->json($this->api('GET', '/api/v1/me/likes/tracks', $bob))['items'], 'likedByMe')));
    }
}
