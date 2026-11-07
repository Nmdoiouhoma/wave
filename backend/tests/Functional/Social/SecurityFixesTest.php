<?php

declare(strict_types=1);

namespace App\Tests\Functional\Social;

use App\Entity\User;
use App\Enum\Visibility;
use App\Tests\Functional\WaveApiTestCase;

/** W-26 F14, F15, F16 (docs/handoff/w26-fix-architect.md): non-regression. */
final class SecurityFixesTest extends WaveApiTestCase
{
    /** F14: one's own like can be removed after the target became invisible. */
    public function testUnlikePlaylistAfterItBecamePrivate(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $playlist = $this->createPlaylist($alice);
        $url = '/api/v1/playlists/'.$playlist->getId().'/like';
        self::assertSame(1, $this->json($this->api('PUT', $url, $bob))['likeCount']);
        $this->em()->getConnection()->executeStatement('UPDATE playlist SET is_public = false WHERE id = ?', [$playlist->getId()->toRfc4122()]);

        $this->assertProblem($this->api('PUT', $url, $bob), 404, 'not_found');
        self::assertSame(['likeCount' => 0, 'liked' => false], $this->likeState($this->api('DELETE', $url, $bob)));
        // Without a like of mine, an invisible target answers like an unknown one (no existence oracle).
        $this->assertProblem($this->api('DELETE', $url, $bob), 404, 'not_found');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT like_count FROM playlist WHERE id = ?', [$playlist->getId()->toRfc4122()]));
        $this->assertProblem($this->api('DELETE', '/api/v1/playlists/0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e/like', $bob), 404, 'not_found');
        $this->assertProblem($this->api('DELETE', '/api/v1/playlists/not-a-uuid/like', $bob), 404, 'not_found');
    }

    /** F15: reorder `trackIds` must be a JSON list; an object with the right ids is refused. */
    public function testReorderRejectsAnObjectOfTrackIds(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $playlist = $this->createPlaylist($alice);
        $tid = $track->getId()->toRfc4122();
        self::assertSame(201, $this->api('POST', '/api/v1/playlists/'.$playlist->getId().'/items', $alice, ['json' => ['trackId' => $tid]])->getStatusCode());

        $this->assertProblem($this->api('PUT', '/api/v1/playlists/'.$playlist->getId().'/order', $alice, ['json' => ['trackIds' => ['a' => $tid]]]), 422, 'validation_failed');
        self::assertSame(204, $this->api('PUT', '/api/v1/playlists/'.$playlist->getId().'/order', $alice, ['json' => ['trackIds' => [$tid]]])->getStatusCode());
    }

    /** F15: 200 playlists per user, then 409 `playlist_limit_reached`. */
    public function testPlaylistLimitPerUser(): void
    {
        $alice = $this->createUser('alice');
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO playlist (id, owner_id, name, is_public, moderation, item_count, like_count, created_at, updated_at)
             SELECT gen_random_uuid(), ?, 'p' || g, true, 'visible', 0, 0, now(), now() FROM generate_series(1, 199) g",
            [$alice->getId()->toRfc4122()],
        );

        self::assertSame(201, $this->api('POST', '/api/v1/playlists', $alice, ['json' => ['name' => '200th']])->getStatusCode());
        $this->assertProblem($this->api('POST', '/api/v1/playlists', $alice, ['json' => ['name' => '201st']]), 409, 'playlist_limit_reached');
        self::assertSame(201, $this->api('POST', '/api/v1/playlists', $this->createUser('bob'), ['json' => ['name' => 'mine']])->getStatusCode(), 'per user');
    }

    /** F15: the owner keeps an item whose track became invisible to them (trackId, track null). */
    public function testOwnerStillSeesUnavailableItems(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($bob));
        $playlist = $this->createPlaylist($alice);
        self::assertSame(201, $this->api('POST', '/api/v1/playlists/'.$playlist->getId().'/items', $alice, ['json' => ['trackId' => $track->getId()->toRfc4122()]])->getStatusCode());
        $track = $this->reload($track);
        $track->setVisibility(Visibility::Private);
        $this->em()->flush();

        $items = $this->json($this->api('GET', '/api/v1/playlists/'.$playlist->getId().'/items', $alice))['items'];
        self::assertSame([[$track->getId()->toRfc4122(), null]], array_map(static fn (array $i): array => [$i['trackId'], $i['track']], $items));
        self::assertSame([], $this->json($this->api('GET', '/api/v1/playlists/'.$playlist->getId().'/items', $this->createUser('carol')))['items']);
        self::assertCount(1, $this->json($this->api('GET', '/api/v1/playlists/'.$playlist->getId().'/items', $bob))['items'], 'the track owner still sees it');
    }

    /** F16: a suspended user is 404 everywhere under /users/{username} except unfollow, and absent from lists. */
    public function testSuspendedUserIsHiddenButCanBeUnfollowed(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        foreach ([[$alice, 'bob'], [$bob, 'alice'], [$carol, 'alice']] as [$as, $target]) {
            self::assertSame(200, $this->api('PUT', '/api/v1/users/'.$target.'/follow', $as)->getStatusCode());
        }
        $this->suspend($bob);

        foreach (['', '/followers', '/following', '/playlists', '/tracks', '/artists'] as $suffix) {
            $this->assertProblem($this->api('GET', '/api/v1/users/bob'.$suffix, $alice), 404, 'not_found');
        }
        $this->assertProblem($this->api('PUT', '/api/v1/users/bob/follow', $carol), 404, 'not_found');
        self::assertSame(['carol'], $this->usernames('/api/v1/users/alice/followers', $alice), 'suspended follower omitted');
        self::assertSame([], $this->usernames('/api/v1/users/alice/following', $alice), 'suspended followed omitted');
        self::assertSame(2, $this->json($this->api('GET', '/api/v1/users/alice', $carol))['followersCount'], 'counters unchanged');

        $r = $this->api('DELETE', '/api/v1/users/bob/follow', $alice);
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT followers_count FROM app_user WHERE id = ?', [$bob->getId()->toRfc4122()]));
        $this->assertProblem($this->api('DELETE', '/api/v1/users/nobody_here/follow', $alice), 404, 'not_found');
    }

    private function suspend(User $user): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE app_user SET suspended_at = now() WHERE id = ?', [$user->getId()->toRfc4122()]);
    }

    /** @return list<string> */
    private function usernames(string $url, User $as): array
    {
        return array_column($this->json($this->api('GET', $url, $as))['items'], 'username');
    }

    /** @return array<int|string, mixed> */
    private function likeState(\Symfony\Contracts\HttpClient\ResponseInterface $r): array
    {
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
        $state = $this->json($r);
        ksort($state);

        return $state;
    }
}
