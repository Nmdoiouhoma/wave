<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\Uid\Uuid;

/** W-72: `GET /admin/users/{id}/activity` and `GET /admin/audit-log` (DATA_MODEL §15). */
final class JournalApiTest extends WaveApiTestCase
{
    public function testAccessIsAdminOnlyAndModeratorIsRefusedBeforeLookup(): void
    {
        $user = $this->createUser('plain');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $url = '/api/v1/admin/users/'.$user->getId()->toRfc4122().'/activity';
        $this->assertProblem($this->api('GET', $url), 401, 'unauthenticated');
        $this->assertProblem($this->api('GET', $url, $user), 403, 'forbidden');
        $this->assertProblem($this->api('GET', $url, $mod), 403, 'forbidden');
        $this->assertProblem($this->api('GET', '/api/v1/admin/users/'.Uuid::v7()->toRfc4122().'/activity', $mod), 403, 'forbidden');
        $this->assertProblem($this->api('GET', '/api/v1/admin/audit-log', $mod), 403, 'forbidden');
        self::assertSame(0, $this->rows('admin.journal_viewed'));
    }

    public function testUserActivityCoversSubjectAndActorAndJournalsTheFirstPageOnly(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $a = $alice->getId()->toRfc4122();
        $b = $bob->getId()->toRfc4122();
        $e1 = $this->event('auth.login_succeeded', $a, $a);
        $e2 = $this->event('report.created', $a, $b, ['reason' => 'spam']); // filed by alice about bob
        $e3 = $this->event('auth.login_failed', null, $a);
        $this->event('auth.login_succeeded', $b, $b); // not alice's
        $e4 = $this->event('report.created', $b, $a); // about alice

        $url = '/api/v1/admin/users/'.$a.'/activity';
        $r = $this->api('GET', $url.'?limit=2', $admin);
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
        $page = $this->json($r);
        self::assertSame([$e4, $e3], array_column($page['items'], 'id'));
        self::assertSame('report', $page['items'][0]['category']);
        self::assertSame($b, $page['items'][0]['actor']['id']);
        self::assertSame($a, $page['items'][0]['subjectUser']['id']);
        self::assertNull($page['items'][1]['actor']);
        self::assertIsString($page['nextCursor']);
        self::assertSame(1, $this->rows('admin.journal_viewed'));

        $next = $this->json($this->api('GET', $url.'?limit=2&cursor='.urlencode($page['nextCursor']), $admin));
        self::assertSame([$e2, $e1], array_column($next['items'], 'id'));
        self::assertSame(['reason' => 'spam'], $next['items'][0]['metadata']);
        self::assertSame(1, $this->rows('admin.journal_viewed'), 'next pages are not journaled');

        $viewed = $this->em()->getConnection()->fetchAssociative("SELECT actor_id, subject_user_id FROM activity_event WHERE type = 'admin.journal_viewed'");
        self::assertSame(['actor_id' => $admin->getId()->toRfc4122(), 'subject_user_id' => $a], $viewed);

        $auth = $this->json($this->api('GET', $url.'?category=auth', $admin));
        self::assertSame([$e3, $e1], array_column($auth['items'], 'id'));
        self::assertNull($auth['nextCursor']);
        // the admin's own journal shows their journal_viewed rows (actor = admin)
        $mine = $this->json($this->api('GET', '/api/v1/admin/users/'.$admin->getId()->toRfc4122().'/activity?category=admin', $admin));
        self::assertNotSame([], $mine['items']);
        self::assertSame('admin.journal_viewed', $mine['items'][0]['type']);
    }

    public function testBadInputs(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $a = $this->createUser('alice')->getId()->toRfc4122();
        $this->assertProblem($this->api('GET', '/api/v1/admin/users/'.Uuid::v7()->toRfc4122().'/activity', $admin), 404, 'not_found');
        $this->assertProblem($this->api('GET', '/api/v1/admin/users/nope/activity', $admin), 404, 'not_found');
        self::assertSame(400, $this->api('GET', '/api/v1/admin/users/'.$a.'/activity?category=nope', $admin)->getStatusCode());
        self::assertSame(400, $this->api('GET', '/api/v1/admin/users/'.$a.'/activity?cursor=garbage', $admin)->getStatusCode());
        self::assertSame(400, $this->api('GET', '/api/v1/admin/audit-log?actorId=nope', $admin)->getStatusCode());
    }

    public function testAuditLogListsAdminEventsOnlyFilteredByActor(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $other = $this->staff('other', 'ROLE_ADMIN');
        $u = $this->createUser('alice')->getId()->toRfc4122();
        $x1 = $this->event('admin.user_suspended', $admin->getId()->toRfc4122(), $u);
        $this->event('auth.login_succeeded', $u, $u);
        $x2 = $this->event('admin.admin_granted', null, $u); // console: no actor
        $x3 = $this->event('admin.user_unsuspended', $other->getId()->toRfc4122(), $u);

        $all = $this->json($this->api('GET', '/api/v1/admin/audit-log', $admin));
        self::assertSame([$x3, $x2, $x1], array_column($all['items'], 'id'));
        $byAdmin = $this->json($this->api('GET', '/api/v1/admin/audit-log?actorId='.$admin->getId()->toRfc4122(), $admin));
        self::assertSame([$x1], array_column($byAdmin['items'], 'id'));
        $paged = $this->json($this->api('GET', '/api/v1/admin/audit-log?limit=1', $admin));
        $p2 = $this->json($this->api('GET', '/api/v1/admin/audit-log?limit=1&cursor='.urlencode((string) $paged['nextCursor']), $admin));
        self::assertSame([$x2], array_column($p2['items'], 'id'));
        self::assertSame(0, $this->rows('admin.journal_viewed'), 'the audit log itself is not journaled');
    }

    private function staff(string $name, string $role): User
    {
        $user = $this->createUser($name);
        $user->setRoles([$role]);
        $this->em()->flush();

        return $user;
    }

    /** @param array<string, string> $metadata */
    private function event(string $type, ?string $actor, ?string $subject, array $metadata = []): string
    {
        usleep(2000); // UUID v7 ordering at ms precision
        $id = Uuid::v7()->toRfc4122();
        $this->em()->getConnection()->insert('activity_event', [
            'id' => $id, 'category' => explode('.', $type)[0], 'type' => $type,
            'actor_id' => $actor, 'subject_user_id' => $subject,
            'metadata' => json_encode((object) $metadata, \JSON_THROW_ON_ERROR), 'created_at' => 'now()',
        ]);

        return $id;
    }

    private function rows(string $type): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM activity_event WHERE type = ?', [$type]);
    }
}
