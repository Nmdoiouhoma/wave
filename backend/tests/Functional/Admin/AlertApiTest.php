<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\Uid\Uuid;

/** W-72: `GET /admin/alerts` and `POST /admin/alerts/{id}/acknowledgement` (DATA_MODEL §16). */
final class AlertApiTest extends WaveApiTestCase
{
    public function testAccessIsAdminOnly(): void
    {
        $user = $this->createUser('plain');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $alert = $this->alert('token_reuse', 'high', $user, '-1 minute');
        $this->assertProblem($this->api('GET', '/api/v1/admin/alerts'), 401, 'unauthenticated');
        $this->assertProblem($this->api('GET', '/api/v1/admin/alerts', $user), 403, 'forbidden');
        $this->assertProblem($this->api('GET', '/api/v1/admin/alerts', $mod), 403, 'forbidden');
        foreach ([$alert, Uuid::v7()->toRfc4122()] as $id) {
            $this->assertProblem($this->api('POST', '/api/v1/admin/alerts/'.$id.'/acknowledgement', $mod, ['json' => ['note' => 'x']]), 403, 'forbidden');
        }
        self::assertSame('open', $this->alertStatus($alert));
    }

    public function testListIsOpenBySeverityThenRecencyWithFiltersAndCursor(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $u = $this->createUser('alice');
        $low = $this->alert('report_burst', 'low', null, '-1 minute', ['subject_target_type' => 'track', 'subject_target_id' => Uuid::v7()->toRfc4122()]);
        $medOld = $this->alert('upload_abuse', 'medium', $u, '-2 hours');
        $medNew = $this->alert('login_failures', 'medium', null, '-5 minutes', ['subject_ip_hash' => random_bytes(16)]);
        $high = $this->alert('token_reuse', 'high', $u, '-3 hours');
        $done = $this->alert('privilege_granted', 'high', $u, '-1 minute', ['status' => 'acknowledged']);

        $r = $this->api('GET', '/api/v1/admin/alerts', $admin);
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
        $items = $this->json($r)['items'];
        self::assertSame([$high, $medNew, $medOld, $low], array_column($items, 'id'));
        self::assertSame($u->getId()->toRfc4122(), $items[0]['subjectUser']['id']);
        self::assertSame(32, \strlen((string) $items[1]['subjectIpHash']));
        self::assertSame('track', $items[3]['subjectTarget']['targetType']);
        self::assertNull($items[0]['subjectTarget']);

        $p1 = $this->json($this->api('GET', '/api/v1/admin/alerts?limit=2', $admin));
        self::assertSame([$high, $medNew], array_column($p1['items'], 'id'));
        $p2 = $this->json($this->api('GET', '/api/v1/admin/alerts?limit=2&cursor='.urlencode((string) $p1['nextCursor']), $admin));
        self::assertSame([$medOld, $low], array_column($p2['items'], 'id'));
        self::assertNull($p2['nextCursor']);

        self::assertSame([$done], array_column($this->json($this->api('GET', '/api/v1/admin/alerts?status=acknowledged', $admin))['items'], 'id'));
        self::assertSame([$medNew, $medOld], array_column($this->json($this->api('GET', '/api/v1/admin/alerts?severity=medium', $admin))['items'], 'id'));
        self::assertSame([$medOld], array_column($this->json($this->api('GET', '/api/v1/admin/alerts?rule=upload_abuse', $admin))['items'], 'id'));
        foreach (['status=nope', 'severity=nope', 'rule=nope', 'cursor=garbage'] as $q) {
            self::assertSame(400, $this->api('GET', '/api/v1/admin/alerts?'.$q, $admin)->getStatusCode(), $q);
        }
    }

    public function testAcknowledgeOnceThen409(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $u = $this->createUser('alice');
        $alert = $this->alert('token_reuse', 'high', $u, '-1 minute');
        $url = '/api/v1/admin/alerts/'.$alert.'/acknowledgement';

        foreach ([[], ['note' => '   '], ['note' => str_repeat('a', 501)], ['note' => 'ok', 'extra' => 1]] as $body) {
            $this->assertProblem($this->api('POST', $url, $admin, ['json' => $body]), 422, 'validation_failed');
        }
        $this->assertProblem($this->api('POST', '/api/v1/admin/alerts/'.Uuid::v7()->toRfc4122().'/acknowledgement', $admin, ['json' => ['note' => 'ok']]), 404, 'not_found');
        $this->assertProblem($this->api('POST', '/api/v1/admin/alerts/nope/acknowledgement', $admin, ['json' => ['note' => 'ok']]), 404, 'not_found');

        $r = $this->api('POST', $url, $admin, ['json' => ['note' => '  Session revoked, user warned.  ']]);
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
        $body = $this->json($r);
        self::assertSame('acknowledged', $body['status']);
        self::assertSame('Session revoked, user warned.', $body['note']);
        self::assertSame($admin->getId()->toRfc4122(), $body['acknowledgedBy']['id']);
        self::assertNotNull($body['acknowledgedAt']);

        $event = $this->em()->getConnection()->fetchAssociative("SELECT actor_id, subject_user_id, target_type, target_id, metadata FROM activity_event WHERE type = 'admin.alert_acknowledged'");
        self::assertSame([$admin->getId()->toRfc4122(), $u->getId()->toRfc4122(), 'alert', $alert], [$event['actor_id'] ?? null, $event['subject_user_id'] ?? null, $event['target_type'] ?? null, $event['target_id'] ?? null]);
        self::assertSame(['note' => 'Session revoked, user warned.', 'rule' => 'token_reuse'], json_decode((string) ($event['metadata'] ?? ''), true));

        $this->assertProblem($this->api('POST', $url, $admin, ['json' => ['note' => 'again']]), 409, 'already_acknowledged');
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne("SELECT count(*) FROM activity_event WHERE type = 'admin.alert_acknowledged'"));
    }

    private function staff(string $name, string $role): User
    {
        $user = $this->createUser($name);
        $user->setRoles([$role]);
        $this->em()->flush();

        return $user;
    }

    /** @param array<string, string> $extra */
    private function alert(string $rule, string $severity, ?User $user, string $lastSeen, array $extra = []): string
    {
        $id = Uuid::v7()->toRfc4122();
        $at = new \DateTimeImmutable($lastSeen)->format('Y-m-d H:i:s.uP');
        $this->em()->getConnection()->insert('security_alert', array_merge([
            'id' => $id, 'rule' => $rule, 'severity' => $severity, 'subject_user_id' => $user?->getId()->toRfc4122(),
            'hit_count' => 1, 'first_seen_at' => $at, 'last_seen_at' => $at, 'status' => 'open', 'created_at' => $at,
        ], $extra), ['subject_ip_hash' => \Doctrine\DBAL\ParameterType::BINARY]);

        return $id;
    }

    private function alertStatus(string $alert): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT status FROM security_alert WHERE id = ?', [$alert]);
    }
}
