<?php

declare(strict_types=1);

namespace App\Tests\Functional\Social;

use App\Tests\Functional\WaveApiTestCase;

/** W-21: follow. */
final class FollowTest extends WaveApiTestCase
{
    public function testFollowIsIdempotentAndCountersExact(): void
    {
        $alice = $this->createUser('alice');
        $this->createUser('bob');

        foreach ([1, 1] as $expected) {
            $r = $this->api('PUT', '/api/v1/users/bob/follow', $alice);
            self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
            self::assertSame(['following' => true, 'followersCount' => $expected], $this->json($r));
        }
        $profile = $this->json($this->api('GET', '/api/v1/users/bob', $alice));
        self::assertSame([1, true], [$profile['followersCount'], $profile['isFollowedByMe']]);
        self::assertSame(1, $this->json($this->api('GET', '/api/v1/me', $alice))['followingCount']);

        foreach ([0, 0] as $expected) {
            self::assertSame(['following' => false, 'followersCount' => $expected], $this->json($this->api('DELETE', '/api/v1/users/bob/follow', $alice)));
        }
        self::assertSame(0, $this->json($this->api('GET', '/api/v1/me', $alice))['followingCount']);
        self::assertFalse($this->json($this->api('GET', '/api/v1/users/bob', $alice))['isFollowedByMe']);

        $this->assertProblem($this->api('PUT', '/api/v1/users/alice/follow', $alice), 422, 'cannot_follow_self');
        $this->assertProblem($this->api('PUT', '/api/v1/users/nobody/follow', $alice), 404, 'not_found');
    }

    public function testFollowerListsAreKeysetPaginatedByFollowDate(): void
    {
        $star = $this->createUser('star');
        $me = $this->createUser('me_user');
        $fans = [];
        foreach (['fan_a', 'fan_b', 'fan_c'] as $name) {
            $fans[$name] = $this->createUser($name);
            $this->api('PUT', '/api/v1/users/star/follow', $fans[$name]);
        }
        $this->api('PUT', '/api/v1/users/fan_b/follow', $me);

        $p1 = $this->json($this->api('GET', '/api/v1/users/star/followers?limit=2', $me));
        self::assertSame(['fan_c', 'fan_b'], array_column($p1['items'], 'username'));
        self::assertSame(['id', 'username', 'displayName', 'avatarUrl', 'followersCount', 'isMe', 'isFollowedByMe'], array_keys($p1['items'][0]));
        self::assertSame([false, true], array_column($p1['items'], 'isFollowedByMe'));
        self::assertSame(1, $p1['items'][1]['followersCount']);
        $p2 = $this->json($this->api('GET', '/api/v1/users/star/followers?limit=2&cursor='.$p1['nextCursor'], $me));
        self::assertSame(['fan_a'], array_column($p2['items'], 'username'));
        self::assertNull($p2['nextCursor']);

        $following = $this->json($this->api('GET', '/api/v1/users/fan_a/following', $fans['fan_a']));
        self::assertSame([['star', false, true]], array_map(static fn (array $u): array => [$u['username'], $u['isMe'], $u['isFollowedByMe']], $following['items']));
        $this->assertProblem($this->api('GET', '/api/v1/users/nobody/followers', $me), 404, 'not_found');
    }

    public function testFollowerListHasNoNPlusOne(): void
    {
        $star = $this->createUser('star');
        $viewer = $this->createUser('viewer');
        for ($i = 0; $i < 6; ++$i) {
            $this->api('PUT', '/api/v1/users/star/follow', $this->createUser('fan'.$i));
        }

        $two = $this->countQueries('GET', '/api/v1/users/star/followers?limit=2', $viewer);
        $six = $this->countQueries('GET', '/api/v1/users/star/followers?limit=6', $viewer);
        self::assertSame($two, $six, 'query count must not grow with the page size');
    }
}
