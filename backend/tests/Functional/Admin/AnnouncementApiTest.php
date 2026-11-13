<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\Uid\Uuid;

/** W-72: admin announcements CRUD and `GET /announcements` (DATA_MODEL §17). */
final class AnnouncementApiTest extends WaveApiTestCase
{
    private const string BASE = '/api/v1/admin/announcements';

    public function testAccessIsAdminOnly(): void
    {
        $user = $this->createUser('plain');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $this->assertProblem($this->api('GET', self::BASE), 401, 'unauthenticated');
        $this->assertProblem($this->api('GET', '/api/v1/announcements'), 401, 'unauthenticated');
        foreach ([$user, $mod] as $as) {
            $this->assertProblem($this->api('GET', self::BASE, $as), 403, 'forbidden');
            $this->assertProblem($this->api('POST', self::BASE, $as, ['json' => ['title' => 't', 'body' => 'b', 'level' => 'info']]), 403, 'forbidden');
            $id = Uuid::v7()->toRfc4122();
            $this->assertProblem($this->api('PATCH', self::BASE.'/'.$id, $as, $this->patch(['title' => 'x'])), 403, 'forbidden');
            $this->assertProblem($this->api('DELETE', self::BASE.'/'.$id, $as), 403, 'forbidden');
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM announcement'));
    }

    public function testCreateValidatesAndJournals(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $bad = [
            [],
            ['title' => '', 'body' => 'b', 'level' => 'info'],
            ['title' => str_repeat('t', 81), 'body' => 'b', 'level' => 'info'],
            ['title' => 't', 'body' => str_repeat('b', 501), 'level' => 'info'],
            ['title' => 't', 'body' => 'b', 'level' => 'urgent'],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'startsAt' => 'tomorrow'],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'startsAt' => '2026-02-31T10:00:00Z'],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'startsAt' => '2026-11-13T10:00:00Z', 'endsAt' => '2026-11-13T10:00:00Z'],
            ['title' => 't', 'body' => 'b', 'level' => 'info', 'html' => '<b>'],
        ];
        foreach ($bad as $body) {
            $this->assertProblem($this->api('POST', self::BASE, $admin, ['json' => $body]), 422, 'validation_failed');
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM announcement'));

        $r = $this->api('POST', self::BASE, $admin, ['json' => ['title' => '  Maintenance ', 'body' => '<b>Down</b> tonight', 'level' => 'warning', 'endsAt' => '2099-01-01T00:00:00+01:00']]);
        self::assertSame(201, $r->getStatusCode(), $r->getContent(false));
        $a = $this->json($r);
        self::assertSame(['Maintenance', '<b>Down</b> tonight', 'warning'], [$a['title'], $a['body'], $a['level']]);
        self::assertSame('2098-12-31T23:00:00Z', $a['endsAt']);
        self::assertNotNull($a['startsAt']);
        self::assertSame(
            ['actor_id' => $admin->getId()->toRfc4122(), 'target_type' => 'announcement', 'target_id' => $a['id'], 'metadata' => '{"level": "warning"}'],
            $this->em()->getConnection()->fetchAssociative("SELECT actor_id, target_type, target_id, metadata FROM activity_event WHERE type = 'admin.announcement_created'"),
        );
    }

    public function testUpdateAndDelete(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $a = $this->json($this->api('POST', self::BASE, $admin, ['json' => ['title' => 'T', 'body' => 'B', 'level' => 'info', 'startsAt' => '2026-01-01T00:00:00Z', 'endsAt' => '2099-01-01T00:00:00Z']]));
        $url = self::BASE.'/'.$a['id'];

        $this->assertProblem($this->api('PATCH', self::BASE.'/'.Uuid::v7()->toRfc4122(), $admin, $this->patch(['title' => 'x'])), 404, 'not_found');
        foreach ([[], ['title' => null], ['level' => null], ['startsAt' => null], ['title' => ' '], ['endsAt' => '2025-01-01T00:00:00Z'], ['startsAt' => '2099-06-01T00:00:00Z'], ['nope' => 1]] as $body) {
            $this->assertProblem($this->api('PATCH', $url, $admin, $this->patch($body)), 422, 'validation_failed');
        }

        usleep(1_100_000); // updatedAt has second precision on the wire
        $r = $this->api('PATCH', $url, $admin, $this->patch(['title' => 'New', 'endsAt' => null]));
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
        $u = $this->json($r);
        self::assertSame(['New', 'B', 'info', null], [$u['title'], $u['body'], $u['level'], $u['endsAt']]);
        self::assertNotSame($a['updatedAt'], $u['updatedAt']);
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT count(*) FROM activity_event WHERE type = 'admin.announcement_updated'"));

        self::assertSame(204, $this->api('DELETE', $url, $admin)->getStatusCode());
        $this->assertProblem($this->api('DELETE', $url, $admin), 404, 'not_found');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT count(*) FROM activity_event WHERE type = 'admin.announcement_deleted' AND target_id = ?", [$a['id']]));
    }

    public function testActiveListAndAdminList(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $user = $this->createUser('alice');
        $mk = fn (string $title, string $level, string $starts, ?string $ends = null): string => (string) $this->json($this->api('POST', self::BASE, $admin, ['json' => ['title' => $title, 'body' => 'b', 'level' => $level, 'startsAt' => $starts, 'endsAt' => $ends]]))['id'];
        $now = new \DateTimeImmutable();
        $at = static fn (string $m): string => $now->modify($m)->format(\DATE_RFC3339);
        $info1 = $mk('info old', 'info', $at('-3 days'));
        $info2 = $mk('info new', 'info', $at('-1 hour'));
        $warn = $mk('warning old', 'warning', $at('-5 days'), $at('+1 day'));
        $mk('expired', 'warning', $at('-5 days'), $at('-1 day'));
        $mk('scheduled', 'warning', $at('+1 day'));
        $info3 = $mk('info mid', 'info', $at('-2 days'));

        $r = $this->api('GET', '/api/v1/announcements', $user);
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
        self::assertSame([$warn, $info2, $info3], array_column($this->json($r)['items'], 'id'));
        self::assertNotContains($info1, array_column($this->json($r)['items'], 'id'));

        $all = $this->json($this->api('GET', self::BASE.'?limit=4', $admin));
        self::assertSame(['scheduled', 'info new', 'info mid', 'info old'], array_column($all['items'], 'title'));
        $rest = $this->json($this->api('GET', self::BASE.'?limit=4&cursor='.urlencode((string) $all['nextCursor']), $admin));
        self::assertCount(2, $rest['items']);
        self::assertNull($rest['nextCursor']);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function patch(array $body): array
    {
        return ['headers' => ['Content-Type' => 'application/merge-patch+json'], 'body' => json_encode((object) $body, \JSON_THROW_ON_ERROR)];
    }

    private function staff(string $name, string $role): User
    {
        $user = $this->createUser($name);
        $user->setRoles([$role]);
        $this->em()->flush();

        return $user;
    }
}
