<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\Uid\Uuid;

/** W-71: `/admin/dashboard`, `/admin/users*` (ADR 013). */
final class AdminUsersTest extends WaveApiTestCase
{
    public function testAccessIsStaffOnlyAndAdminRoutesRejectModeratorsBeforeAnyLookup(): void
    {
        $user = $this->createUser('plain');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $unknown = Uuid::v7()->toRfc4122();

        $this->assertProblem($this->api('GET', '/api/v1/admin/users'), 401, 'unauthenticated');
        $this->assertProblem($this->api('GET', '/api/v1/admin/dashboard', $user), 403, 'forbidden');
        $this->assertProblem($this->api('GET', '/api/v1/admin/users/'.$user->getId(), $user), 403, 'forbidden');
        $this->assertProblem($this->api('PUT', '/api/v1/admin/users/'.$unknown.'/role', $mod, ['json' => ['role' => 'ROLE_USER']]), 403, 'forbidden');
        $this->assertProblem($this->api('PUT', '/api/v1/admin/users/'.$user->getId().'/role', $mod, ['json' => ['role' => 'ROLE_MODERATOR']]), 403, 'forbidden');
        $this->assertProblem($this->api('GET', '/api/v1/admin/users/'.$unknown, $mod), 404, 'not_found');
        $this->assertProblem($this->api('GET', '/api/v1/admin/users/not-a-uuid', $mod), 404, 'not_found');
    }

    public function testListHidesEmailFromModeratorsAndFiltersPagesNewestFirst(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $alice = $this->createUser('alice', email: 'zx81@mail.test');
        $bob = $this->createUser('bob', verified: false);
        $carol = $this->createUser('carol');
        $carol->suspend();
        $this->em()->flush();

        $page = $this->json($this->api('GET', '/api/v1/admin/users?limit=2', $mod));
        self::assertSame([$carol->getId()->toRfc4122(), $bob->getId()->toRfc4122()], array_column($page['items'], 'id'));
        self::assertNull($page['items'][0]['email']);
        self::assertIsString($page['nextCursor']);
        $next = $this->json($this->api('GET', '/api/v1/admin/users?limit=2&cursor='.$page['nextCursor'], $mod));
        self::assertSame([$alice->getId()->toRfc4122(), $mod->getId()->toRfc4122()], array_column($next['items'], 'id'));

        $asAdmin = $this->json($this->api('GET', '/api/v1/admin/users?q=ZX81@mail.test', $admin));
        self::assertSame(['zx81@mail.test'], array_column($asAdmin['items'], 'email'));
        self::assertSame([], $this->json($this->api('GET', '/api/v1/admin/users?q=zx81@mail.test', $mod))['items'], 'moderators cannot search by email');
        self::assertSame(['alice'], array_column($this->json($this->api('GET', '/api/v1/admin/users?q=alic', $mod))['items'], 'username'));

        self::assertSame(['carol'], $this->usernames('status=suspended', $mod));
        self::assertSame(['bob'], $this->usernames('status=unverified', $mod));
        self::assertSame(['mod'], $this->usernames('role=ROLE_MODERATOR', $mod));
        self::assertSame(['boss'], $this->usernames('role=ROLE_ADMIN', $mod));
        self::assertSame('ROLE_ADMIN', $this->json($this->api('GET', '/api/v1/admin/users?role=ROLE_ADMIN', $mod))['items'][0]['role']);
        $this->assertProblem($this->api('GET', '/api/v1/admin/users?status=gone', $mod), 400, 'malformed_request');
        $this->assertProblem($this->api('GET', '/api/v1/admin/users?limit=99', $mod), 400, 'malformed_request');
    }

    public function testDetailCountsAndEmailVisibility(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $alice = $this->createUser('alice', email: 'alice@example.com');
        $this->createTrack($this->createArtist($alice));

        $detail = $this->json($this->api('GET', '/api/v1/admin/users/'.$alice->getId(), $admin));
        self::assertSame('alice@example.com', $detail['email']);
        self::assertSame(1, $detail['counts']['tracks']);
        self::assertSame(1, $detail['counts']['artists']);
        self::assertSame(0, $detail['openReportCount']);
        self::assertSame(0, $detail['openAlertCount']);
        self::assertNull($detail['lastSuspension']);
        $forMod = $this->json($this->api('GET', '/api/v1/admin/users/'.$alice->getId(), $mod));
        self::assertNull($forMod['email']);
        self::assertNull($forMod['openAlertCount']);
    }

    public function testSuspendAndUnsuspendFollowTheErrorOrderAndJournalOneRowEach(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $other = $this->staff('mod2', 'ROLE_MODERATOR');
        $alice = $this->createUser('alice');
        $url = '/api/v1/admin/users/'.$alice->getId().'/suspension';

        $this->assertProblem($this->api('POST', $url, $mod, ['json' => []]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', '/api/v1/admin/users/'.Uuid::v7().'/suspension', $mod, ['json' => ['note' => 'x']]), 404, 'not_found');
        $this->assertProblem($this->api('POST', '/api/v1/admin/users/'.$mod->getId().'/suspension', $mod, ['json' => ['note' => 'x']]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', '/api/v1/admin/users/'.$other->getId().'/suspension', $mod, ['json' => ['note' => 'x']]), 403, 'forbidden');
        $this->assertProblem($this->api('POST', '/api/v1/admin/users/'.$admin->getId().'/suspension', $mod, ['json' => ['note' => 'x']]), 403, 'forbidden');
        $this->assertProblem($this->api('POST', '/api/v1/admin/users/'.$admin->getId().'/suspension', $this->staff('boss2', 'ROLE_ADMIN'), ['json' => ['note' => 'x']]), 403, 'forbidden');
        self::assertSame([], $this->adminEvents());

        $response = $this->api('POST', $url, $mod, ['json' => ['note' => '  spam wave  ']]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        $body = $this->json($response);
        self::assertNotNull($body['suspendedAt']);
        self::assertSame('spam wave', $body['lastSuspension']['note']);
        self::assertSame('mod', $body['lastSuspension']['by']['username']);
        $this->assertProblem($this->api('POST', $url, $mod, ['json' => ['note' => 'again']]), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/me', $alice), 403, 'account_suspended');
        self::assertSame(200, $this->api('POST', '/api/v1/admin/users/'.$other->getId().'/suspension', $admin, ['json' => ['note' => 'mod abuse']])->getStatusCode());

        $response = $this->api('DELETE', $url, $mod);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertNull($this->json($response)['suspendedAt']);
        $this->assertProblem($this->api('DELETE', $url, $mod), 422, 'validation_failed');

        self::assertSame([
            ['admin.user_suspended', $mod->getId()->toRfc4122(), $alice->getId()->toRfc4122(), 'user', $alice->getId()->toRfc4122(), 'spam wave'],
            ['admin.user_suspended', $admin->getId()->toRfc4122(), $other->getId()->toRfc4122(), 'user', $other->getId()->toRfc4122(), 'mod abuse'],
            ['admin.user_unsuspended', $mod->getId()->toRfc4122(), $alice->getId()->toRfc4122(), 'user', $alice->getId()->toRfc4122(), null],
        ], $this->adminEvents());
    }

    public function testRoleChangeRulesAndEffectAtTheNextRequest(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $alice = $this->createUser('alice');
        $url = '/api/v1/admin/users/'.$mod->getId().'/role';

        $this->assertProblem($this->api('PUT', $url, $admin, ['json' => ['role' => 'ROLE_ADMIN']]), 422, 'validation_failed');
        $this->assertProblem($this->api('PUT', '/api/v1/admin/users/'.$admin->getId().'/role', $admin, ['json' => ['role' => 'ROLE_USER']]), 403, 'forbidden');
        $this->assertProblem($this->api('PUT', '/api/v1/admin/users/'.$this->staff('boss2', 'ROLE_ADMIN')->getId().'/role', $admin, ['json' => ['role' => 'ROLE_USER']]), 403, 'forbidden');
        $this->assertProblem($this->api('PUT', '/api/v1/admin/users/'.Uuid::v7().'/role', $admin, ['json' => ['role' => 'ROLE_USER']]), 404, 'not_found');

        $same = $this->api('PUT', '/api/v1/admin/users/'.$alice->getId().'/role', $admin, ['json' => ['role' => 'ROLE_USER']]);
        self::assertSame(200, $same->getStatusCode());
        self::assertSame([], $this->adminEvents(), 'a no-op change is not journaled');

        $oldToken = $this->tokenFor($mod);
        self::assertSame(200, $this->client->request('GET', '/api/v1/admin/dashboard', ['headers' => ['Authorization' => 'Bearer '.$oldToken, 'Accept' => 'application/json']])->getStatusCode());
        $response = $this->api('PUT', $url, $admin, ['json' => ['role' => 'ROLE_USER', 'note' => 'stepped down']]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('ROLE_USER', $this->json($response)['role']);
        $this->assertProblem($this->client->request('GET', '/api/v1/admin/dashboard', ['headers' => ['Authorization' => 'Bearer '.$oldToken, 'Accept' => 'application/json']]), 403, 'forbidden');

        self::assertSame([
            ['admin.role_changed', $admin->getId()->toRfc4122(), $mod->getId()->toRfc4122(), 'user', $mod->getId()->toRfc4122(), 'stepped down'],
        ], $this->adminEvents());
        $metadata = json_decode((string) $this->em()->getConnection()->fetchOne("SELECT metadata FROM activity_event WHERE type = 'admin.role_changed'"), true);
        self::assertSame(['ROLE_MODERATOR', 'ROLE_USER'], [$metadata['from'], $metadata['to']]);
    }

    public function testDashboardHidesAdminBlocksFromModerators(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $this->createUser('alice', verified: false);

        $forAdmin = $this->json($this->api('GET', '/api/v1/admin/dashboard', $admin));
        self::assertSame(['total' => 3, 'new24h' => 3, 'new7d' => 3, 'suspended' => 0, 'unverified' => 1, 'moderators' => 1, 'admins' => 1], $forAdmin['users']);
        self::assertSame(['open' => 0, 'openHigh' => 0, 'openMedium' => 0, 'openLow' => 0], $forAdmin['alerts']);
        self::assertSame(['active' => 0, 'scheduled' => 0], $forAdmin['announcements']);
        self::assertSame(['openReportTargets' => 0, 'openReports' => 0, 'hiddenContent24h' => 0], $forAdmin['moderation']);

        $forMod = $this->json($this->api('GET', '/api/v1/admin/dashboard', $mod));
        self::assertNull($forMod['alerts']);
        self::assertNull($forMod['announcements']);
        self::assertNull($forMod['users'], 'ADR 013: moderation counters only');
        self::assertNull($forMod['content']);
        self::assertSame($forAdmin['moderation'], $forMod['moderation']);
    }

    private function staff(string $username, string $role): User
    {
        $user = $this->createUser($username);
        $user->setRoles([$role]);
        $this->em()->flush();

        return $user;
    }

    /** @return list<string> */
    private function usernames(string $query, User $as): array
    {
        return array_column($this->json($this->api('GET', '/api/v1/admin/users?'.$query, $as))['items'], 'username');
    }

    /** @return list<list<string|null>> type, actor, subject, target type, target id, note — oldest first */
    private function adminEvents(): array
    {
        return array_map(array_values(...), $this->em()->getConnection()->fetchAllAssociative(
            "SELECT type, actor_id::text, subject_user_id::text, target_type, target_id::text, metadata->>'note' FROM activity_event WHERE type LIKE 'admin.%' ORDER BY id",
        ));
    }
}
