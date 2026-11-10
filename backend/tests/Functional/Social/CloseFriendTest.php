<?php

declare(strict_types=1);

namespace App\Tests\Functional\Social;

use App\Tests\Functional\WaveApiTestCase;

/** W-47: the caller's single close-friends list (FB-18, ADR 012). Members are followers only. */
final class CloseFriendTest extends WaveApiTestCase
{
    public function testPutAndDeleteAreIdempotentAndRestrictedToFollowers(): void
    {
        $owner = $this->createUser('owner');
        $fan = $this->createUser('fan');
        $this->createUser('stranger');
        $this->api('PUT', '/api/v1/users/owner/follow', $fan);

        foreach ([1, 2] as $ignored) {
            $r = $this->api('PUT', '/api/v1/me/close-friends/fan', $owner);
            self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
            self::assertSame(['isCloseFriend' => true], $this->json($r));
        }
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM close_friend'));

        $this->assertProblem($this->api('PUT', '/api/v1/me/close-friends/stranger', $owner), 422, 'not_a_follower');
        $this->assertProblem($this->api('PUT', '/api/v1/me/close-friends/owner', $owner), 422, 'not_a_follower');
        $this->assertProblem($this->api('PUT', '/api/v1/me/close-friends/nobody', $owner), 404, 'not_found');
        // The reverse edge does not count: owner does not follow fan.
        $this->assertProblem($this->api('PUT', '/api/v1/me/close-friends/owner', $fan), 422, 'not_a_follower');

        foreach ([1, 2] as $ignored) {
            self::assertSame(['isCloseFriend' => false], $this->json($this->api('DELETE', '/api/v1/me/close-friends/fan', $owner)));
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM close_friend'));
        self::assertSame(401, $this->api('GET', '/api/v1/me/close-friends')->getStatusCode());
    }

    public function testListsMembersOrFollowersWithAScopeBoundCursor(): void
    {
        $owner = $this->createUser('owner');
        foreach (['fan_a', 'fan_b', 'fan_c', 'fan_d'] as $name) {
            $this->api('PUT', '/api/v1/users/owner/follow', $this->createUser($name));
        }
        foreach (['fan_a', 'fan_c', 'fan_d'] as $name) {
            $this->api('PUT', '/api/v1/me/close-friends/'.$name, $owner);
        }
        $suspended = $this->em()->getRepository(\App\Entity\User::class)->findOneBy(['username' => 'fan_d']);
        self::assertNotNull($suspended);
        $suspended->suspend();
        $this->em()->flush();

        $p1 = $this->json($this->api('GET', '/api/v1/me/close-friends?limit=1', $owner));
        self::assertSame(['fan_c'], array_column($p1['items'], 'username'), 'members by default, newest first, suspended omitted');
        self::assertSame(['id', 'username', 'displayName', 'avatarUrl', 'isCloseFriend'], array_keys($p1['items'][0]));
        self::assertNotNull($p1['nextCursor']);
        $p2 = $this->json($this->api('GET', '/api/v1/me/close-friends?limit=1&cursor='.urlencode($p1['nextCursor']), $owner));
        self::assertSame(['fan_a'], array_column($p2['items'], 'username'));
        self::assertNull($p2['nextCursor']);

        $all = $this->json($this->api('GET', '/api/v1/me/close-friends?scope=followers', $owner));
        self::assertSame(['fan_c', 'fan_b', 'fan_a'], array_column($all['items'], 'username'));
        self::assertSame([true, false, true], array_column($all['items'], 'isCloseFriend'));

        $this->assertProblem($this->api('GET', '/api/v1/me/close-friends?scope=followers&cursor='.urlencode($p1['nextCursor']), $owner), 400, 'invalid_cursor');
        $r = $this->api('GET', '/api/v1/me/close-friends?scope=everyone', $owner);
        self::assertSame(400, $r->getStatusCode(), $r->getContent(false));

        // A suspended member can still be removed.
        self::assertSame(['isCloseFriend' => false], $this->json($this->api('DELETE', '/api/v1/me/close-friends/fan_d', $owner)));
    }

    public function testUnfollowingLeavesTheListAndRefollowingDoesNotComeBack(): void
    {
        $owner = $this->createUser('owner');
        $fan = $this->createUser('fan');
        $this->api('PUT', '/api/v1/users/owner/follow', $fan);
        $this->api('PUT', '/api/v1/me/close-friends/fan', $owner);

        $this->api('DELETE', '/api/v1/users/owner/follow', $fan);
        self::assertSame([], $this->json($this->api('GET', '/api/v1/me/close-friends', $owner))['items']);
        $this->api('PUT', '/api/v1/users/owner/follow', $fan);
        self::assertSame([], $this->json($this->api('GET', '/api/v1/me/close-friends', $owner))['items']);
        self::assertSame([false], array_column($this->json($this->api('GET', '/api/v1/me/close-friends?scope=followers', $owner))['items'], 'isCloseFriend'));
    }
}
