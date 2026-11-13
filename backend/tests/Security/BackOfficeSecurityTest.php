<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * W-77, back-office review (ADR 013, W-70..W-72): what the lots' own tests leave out. Every
 * `/admin` route refused before any lookup (anonymous, user, moderator on admin-only routes,
 * with an unknown id and an invalid body); staff matrix on unsuspension and role bodies; the
 * dashboard cache whatever the first caller; announcements as inert text with a strict body;
 * journal privacy under real requests (spoofed X-Forwarded-For, hostile user agent, secrets and
 * user text never stored); owner deletion routes after the ContentDeleter refactor.
 */
final class BackOfficeSecurityTest extends SecurityTestCase
{
    private const string PEPPER = 'test-activity-ip-pepper';

    public function testEveryAdminRouteRefusesBeforeAnyLookupOrBody(): void
    {
        $user = $this->createUser('plain');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $unknown = Uuid::v7()->toRfc4122();
        // [method, path, admin only]
        $routes = [
            ['GET', '/api/v1/admin/dashboard', false],
            ['GET', '/api/v1/admin/users', false],
            ['GET', '/api/v1/admin/users/'.$unknown, false],
            ['POST', '/api/v1/admin/users/'.$unknown.'/suspension', false],
            ['DELETE', '/api/v1/admin/users/'.$unknown.'/suspension', false],
            ['PUT', '/api/v1/admin/users/'.$unknown.'/role', true],
            ['PUT', '/api/v1/admin/users/'.$mod->getId().'/role', true],
            ['GET', '/api/v1/admin/content?type=track', false],
            ['POST', '/api/v1/admin/content/track/'.$unknown.'/deletion', true],
            ['GET', '/api/v1/admin/users/'.$unknown.'/activity', true],
            ['GET', '/api/v1/admin/users/'.$user->getId().'/activity', true],
            ['GET', '/api/v1/admin/audit-log', true],
            ['GET', '/api/v1/admin/alerts', true],
            ['POST', '/api/v1/admin/alerts/'.$unknown.'/acknowledgement', true],
            ['GET', '/api/v1/admin/announcements', true],
            ['POST', '/api/v1/admin/announcements', true],
            ['PATCH', '/api/v1/admin/announcements/'.$unknown, true],
            ['DELETE', '/api/v1/admin/announcements/'.$unknown, true],
        ];
        foreach ($routes as [$method, $path, $adminOnly]) {
            $options = 'GET' === $method ? [] : [
                'body' => '{"unexpected": true}',
                'headers' => ['Content-Type' => 'PATCH' === $method ? 'application/merge-patch+json' : 'application/json'],
            ];
            $label = $method.' '.$path;
            $this->assertStatusAndCode($this->api($method, $path, null, $options), 401, 'unauthenticated', $label.' anonymous');
            $this->assertStatusAndCode($this->api($method, $path, $user, $options), 403, 'forbidden', $label.' user');
            if ($adminOnly) {
                $this->assertStatusAndCode($this->api($method, $path, $mod, $options), 403, 'forbidden', $label.' moderator');
            }
        }
        $this->assertStatusAndCode($this->api('GET', '/api/v1/announcements'), 401, 'unauthenticated', 'banner anonymous');
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT count(*) FROM activity_event WHERE category = 'admin'"), 'a refused call journals nothing (journal_viewed included)');
    }

    public function testStaffMatrixOnUnsuspensionAndRoleBodies(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $otherMod = $this->staff('mod2', 'ROLE_MODERATOR');
        $otherAdmin = $this->staff('boss2', 'ROLE_ADMIN');
        $alice = $this->createUser('alice');
        // An admin can only become suspended outside the API (console grant to a suspended account).
        $this->db()->executeStatement('UPDATE app_user SET suspended_at = now() WHERE id IN (?, ?)', [$otherAdmin->getId()->toRfc4122(), $otherMod->getId()->toRfc4122()]);

        $unsuspend = fn (User $target, User $as): Response => $this->bearer('DELETE', '/api/v1/admin/users/'.$target->getId().'/suspension', $as);
        $this->assertRawProblem($unsuspend($otherAdmin, $mod), 403, 'forbidden');
        $this->assertRawProblem($unsuspend($otherAdmin, $admin), 403, 'forbidden');
        $this->assertRawProblem($unsuspend($otherMod, $mod), 403, 'forbidden');
        $this->assertRawProblem($unsuspend($admin, $admin), 422, 'validation_failed');
        self::assertSame(200, $unsuspend($otherMod, $admin)->getStatusCode());

        $role = fn (User $target, User $as, string $body): Response => $this->bearer('PUT', '/api/v1/admin/users/'.$target->getId().'/role', $as, $body);
        $this->assertRawProblem($role($mod, $mod, '{"role":"ROLE_MODERATOR"}'), 403, 'forbidden');
        $this->assertRawProblem($role($alice, $mod, '{"role":"ROLE_MODERATOR"}'), 403, 'forbidden');
        foreach ([
            '{"role":"ROLE_MODERATOR","roles":["ROLE_ADMIN"]}',
            '{"role":["ROLE_ADMIN"]}',
            '{"role":"role_admin"}',
            '{"role":"ROLE_SUPER_ADMIN"}',
            '{"role":"ROLE_ADMIN"}',
            '{"role":"ROLE_MODERATOR","note":"'.str_repeat('n', 501).'"}',
            '{"role":"ROLE_MODERATOR\u0000"}',
        ] as $body) {
            self::assertSame(422, $role($alice, $admin, $body)->getStatusCode(), $body);
        }
        self::assertSame([], $this->roles($alice), 'no body changed the roles');
        self::assertSame(['ROLE_MODERATOR'], $this->roles($mod));
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT count(*) FROM activity_event WHERE type = 'admin.role_changed'"));
    }

    /** The cached counters must not depend on who filled the cache. */
    public function testDashboardAdminBlocksDoNotDependOnTheFirstCaller(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');

        $first = $this->json($this->api('GET', '/api/v1/admin/dashboard', $mod));
        self::assertNull($first['alerts']);
        self::assertNull($first['announcements']);
        $second = $this->json($this->api('GET', '/api/v1/admin/dashboard', $admin));
        self::assertIsArray($second['alerts']);
        self::assertIsArray($second['announcements']);
        $third = $this->json($this->api('GET', '/api/v1/admin/dashboard', $mod));
        self::assertNull($third['alerts']);
        self::assertArrayNotHasKey('email', $third['users']);
    }

    public function testAnnouncementTextIsInertAndTheBodyIsStrict(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $user = $this->createUser('alice');
        $title = '<img src=x onerror=alert(1)>';
        $body = "</script><script>alert(document.cookie)</script>\u{202E}gnp.exe";

        $created = $this->api('POST', '/api/v1/admin/announcements', $admin, ['json' => ['title' => $title, 'body' => $body, 'level' => 'warning', 'startsAt' => '2026-01-01T00:00:00Z']]); // explicit: see W-77 finding on the truncated :now
        self::assertSame(201, $created->getStatusCode(), $created->getContent(false));
        self::assertStringStartsWith('application/json', $created->getHeaders()['content-type'][0]);

        $banner = $this->api('GET', '/api/v1/announcements', $user);
        self::assertStringStartsWith('application/json', $banner->getHeaders()['content-type'][0]);
        $items = $this->json($banner)['items'];
        self::assertCount(1, $items);
        self::assertSame($title, $items[0]['title'], 'stored and served verbatim, rendering is the client\'s job (text only)');
        self::assertSame($body, $items[0]['body']);
        self::assertSame(['body', 'endsAt', 'id', 'level', 'startsAt', 'title', 'updatedAt'], $this->sortedKeys($items[0]), 'no author, no email in the banner');

        $id = $items[0]['id'];
        $bad = [
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'createdBy' => $user->getId()->toRfc4122()],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'id' => Uuid::v7()->toRfc4122()],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'updatedAt' => '2020-01-01T00:00:00Z'],
            ['title' => 't', 'body' => 'b', 'level' => 'critical'],
            ['title' => str_repeat('t', 81), 'body' => 'b', 'level' => 'info'],
            ['title' => "t\u{0000}", 'body' => 'b', 'level' => 'info'],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'startsAt' => '2026-11-13 10:00:00'],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'startsAt' => '2026-11-13T10:00:00'],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'startsAt' => '2026-02-30T10:00:00Z'],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'startsAt' => '2026-11-13T24:00:00Z'],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'startsAt' => '2026-11-13T10:61:00Z'],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'startsAt' => '@1700000000'],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'startsAt' => 'now'],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'startsAt' => '2030-01-01T00:00:00Z', 'endsAt' => '2030-01-01T00:00:00Z'],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'startsAt' => '2030-01-01T01:00:00+01:00', 'endsAt' => '2030-01-01T00:00:00Z'],
        ];
        foreach ($bad as $payload) {
            $response = $this->api('POST', '/api/v1/admin/announcements', $admin, ['json' => $payload]);
            self::assertSame(422, $response->getStatusCode(), json_encode($payload).' → '.$response->getContent(false));
        }
        $patch = fn (array $payload) => $this->api('PATCH', '/api/v1/admin/announcements/'.$id, $admin, [
            'body' => json_encode($payload), 'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertSame(422, $patch(['createdBy' => null])->getStatusCode());
        self::assertSame(422, $patch(['title' => null])->getStatusCode());
        self::assertSame(422, $patch(['startsAt' => '2999-01-01T00:00:00Z', 'endsAt' => '2998-01-01T00:00:00Z'])->getStatusCode());
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT count(*) FROM announcement'), 'no invalid body created a row');

        // Scheduled and expired announcements stay off the banner.
        self::assertSame(200, $patch(['startsAt' => '2999-01-01T00:00:00Z'])->getStatusCode());
        self::assertSame([], $this->json($this->api('GET', '/api/v1/announcements', $user))['items']);
        self::assertSame(200, $patch(['startsAt' => '2020-01-01T00:00:00Z', 'endsAt' => '2020-01-02T00:00:00Z'])->getStatusCode());
        self::assertSame([], $this->json($this->api('GET', '/api/v1/announcements', $user))['items']);
    }

    public function testJournalStoresNoSecretNoUserTextNoFullIpEvenWithSpoofedHeaders(): void
    {
        $owner = $this->createUser('owner');
        $reporter = $this->createUser('reporter');
        $server = [
            'REMOTE_ADDR' => '203.0.113.77',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
            'HTTP_USER_AGENT' => "Evil\x1b[31m<script>x</script>".str_repeat('A', 400),
        ];
        $email = 'leak.marker@example.test';
        $password = 'Marker-Secret-Pa55!';

        $register = $this->rawJson('POST', '/api/v1/auth/register', ['email' => $email, 'password' => $password, 'username' => 'markeruser', 'displayName' => 'DisplayMarker'], $server);
        self::assertSame(201, $register->getStatusCode(), (string) $register->getContent());
        $this->forgetCookies();
        self::assertSame(401, $this->rawJson('POST', '/api/v1/auth/login', ['email' => $email, 'password' => 'Wrong-Pa55-Marker'], $server)->getStatusCode());
        self::assertSame(401, $this->rawJson('POST', '/api/v1/auth/login', ['email' => 'ghost.marker@example.test', 'password' => $password], $server)->getStatusCode());
        $login = $this->rawJson('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password], $server);
        self::assertSame(200, $login->getStatusCode(), (string) $login->getContent());
        $jwt = (string) (json_decode((string) $login->getContent(), true)['accessToken'] ?? '');
        $report = $this->rawJson('POST', '/api/v1/reports', [
            'targetType' => 'user', 'targetId' => $owner->getId()->toRfc4122(), 'reason' => 'spam', 'details' => 'REPORT-DETAILS-MARKER',
        ], $server + ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($reporter)]);
        self::assertLessThan(300, $report->getStatusCode(), (string) $report->getContent());

        $rows = $this->db()->fetchFirstColumn('SELECT row_to_json(e)::text FROM activity_event e');
        self::assertGreaterThanOrEqual(5, \count($rows), implode("\n", $rows));
        $secrets = [$email, 'leak.marker', $password, 'Wrong-Pa55-Marker', 'ghost.marker', 'REPORT-DETAILS-MARKER', 'DisplayMarker', '203.0.113.77', '198.51.100', '\u001b'];
        if ('' !== $jwt) {
            $secrets[] = substr($jwt, -20);
        }
        foreach ($rows as $row) {
            foreach ($secrets as $secret) {
                self::assertStringNotContainsStringIgnoringCase($secret, $row, 'leaked into the journal: '.$row);
            }
        }
        $expectedHash = substr(hash_hmac('sha256', '203.0.113.77', self::PEPPER, true), 0, 16);
        foreach ($this->db()->fetchAllAssociative('SELECT type, ip_prefix, ip_hash, user_agent FROM activity_event WHERE ip_prefix IS NOT NULL') as $row) {
            self::assertSame('203.0.113.0/24', $row['ip_prefix'], $row['type'].': X-Forwarded-For from an untrusted peer must be ignored');
            $hash = \is_resource($row['ip_hash']) ? stream_get_contents($row['ip_hash']) : $row['ip_hash'];
            self::assertSame(bin2hex($expectedHash), bin2hex((string) $hash), $row['type']);
            self::assertLessThanOrEqual(200, mb_strlen((string) $row['user_agent']));
        }
        self::assertSame(
            ['account.registered', 'auth.login_failed', 'auth.login_succeeded', 'report.created'],
            $this->db()->fetchFirstColumn("SELECT DISTINCT type FROM activity_event WHERE ip_prefix = '203.0.113.0/24' ORDER BY type"),
        );
    }

    /** ContentDeleter refactor (W-71): the owner routes still refuse another user and delete nothing. */
    public function testOwnerDeletionRoutesStillRefuseOtherUsers(): void
    {
        $owner = $this->createUser('owner');
        $stranger = $this->createUser('stranger');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $artist = $this->createArtist($owner, 'Owned', 'owned');
        $track = $this->createTrack($artist, 'Kept');
        $playlist = $this->createPlaylist($owner, 'Kept', true);

        foreach ([$stranger, $mod] as $who) {
            foreach (['/api/v1/tracks/'.$track->getId(), '/api/v1/playlists/'.$playlist->getId(), '/api/v1/artists/owned'] as $path) {
                $status = $this->api('DELETE', $path, $who)->getStatusCode();
                self::assertContains($status, [403, 404], $path.' as '.$who->getUsername());
            }
        }
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT count(*) FROM track'));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT count(*) FROM playlist'));
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT count(*) FROM artist'));
        $this->assertStatusAndCode(
            $this->api('POST', '/api/v1/admin/content/track/'.$track->getId().'/deletion', $mod, ['json' => ['note' => 'x']]),
            403,
            'forbidden',
            'moderator content deletion',
        );
    }

    public function testAlertAcknowledgementBodyIsStrict(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $alice = $this->createUser('alice');
        $id = Uuid::v7()->toRfc4122();
        $this->db()->executeStatement(
            "INSERT INTO security_alert (id, rule, severity, subject_user_id, first_seen_at, last_seen_at, status, created_at) VALUES (?, 'token_reuse', 'high', ?, now(), now(), 'open', now())",
            [$id, $alice->getId()->toRfc4122()],
        );
        $url = '/api/v1/admin/alerts/'.$id.'/acknowledgement';
        foreach ([['note' => 'ok', 'status' => 'open'], ['note' => 'ok', 'acknowledgedBy' => $alice->getId()->toRfc4122()], ['note' => '   '], ['note' => str_repeat('n', 501)], []] as $payload) {
            self::assertSame(422, $this->api('POST', $url, $admin, ['json' => (object) $payload])->getStatusCode(), (string) json_encode($payload));
        }
        self::assertSame('open', $this->db()->fetchOne('SELECT status FROM security_alert WHERE id = ?', [$id]));
        self::assertSame(200, $this->api('POST', $url, $admin, ['json' => ['note' => 'checked']])->getStatusCode());
    }

    private function staff(string $username, string $role): User
    {
        $user = $this->createUser($username);
        $user->setRoles([$role]);
        $this->em()->flush();

        return $user;
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }

    /** @return list<string> */
    private function roles(User $user): array
    {
        /** @var list<string> $roles */
        $roles = json_decode((string) $this->db()->fetchOne('SELECT roles FROM app_user WHERE id = ?', [$user->getId()->toRfc4122()]), true);

        return $roles;
    }

    private function bearer(string $method, string $uri, User $as, ?string $body = null): Response
    {
        return $this->raw($method, $uri, $body, ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($as)]);
    }

    private function assertRawProblem(Response $response, int $status, string $code): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame($code, self::problemCode($response), (string) $response->getContent());
    }

    private function assertStatusAndCode(\Symfony\Contracts\HttpClient\ResponseInterface $response, int $status, string $code, string $label): void
    {
        self::assertSame($status, $response->getStatusCode(), $label.' → '.$response->getContent(false));
        self::assertSame($code, $this->json($response)['code'] ?? null, $label);
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return list<string>
     */
    private function sortedKeys(array $item): array
    {
        $keys = array_keys($item);
        sort($keys);

        return $keys;
    }
}
