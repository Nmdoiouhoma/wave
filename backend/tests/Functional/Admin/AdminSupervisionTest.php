<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\Uid\Uuid;

/** W-88: `GET /admin/dashboard/supervision` (FB-34, ADR 013 §2, DATA_MODEL §16 note). */
final class AdminSupervisionTest extends WaveApiTestCase
{
    private const string URL = '/api/v1/admin/dashboard/supervision';

    public function testAccessRefusedAndModeratorGetsNullShape(): void
    {
        $user = $this->createUser('plain');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $this->alert('high', 'token_reuse', new \DateTimeImmutable('-5 minutes'), $user);

        $this->assertProblem($this->api('GET', self::URL), 401, 'unauthenticated');
        $this->assertProblem($this->api('GET', self::URL, $user), 403, 'forbidden');

        $r = $this->json($this->api('GET', self::URL, $mod));
        self::assertSame(['global', 'signals', 'hourly', 'updatedAt'], array_keys($r));
        self::assertNull($r['global'], 'ADR 013: monitoring is admin-only');
        self::assertSame([], $r['signals']);
        self::assertNull($r['hourly']);
        self::assertIsString($r['updatedAt']);
    }

    public function testModeratorAfterAdminNeverGetsTheCachedAdminPayload(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $this->alert('high', 'token_reuse', new \DateTimeImmutable('-5 minutes'), $admin);

        self::assertSame('critique', $this->json($this->api('GET', self::URL, $admin))['global']);
        $r = $this->json($this->api('GET', self::URL, $mod));
        self::assertNull($r['global']);
        self::assertSame([], $r['signals']);
    }

    public function testSignalsLevelsHourlyAndNoPersonalData(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $alice = $this->createUser('alice', true, 'alice-secret@example.com');
        $bob = $this->createUser('bob');
        $now = new \DateTimeImmutable();
        $recent = $now->modify('-2 minutes');

        // Open: one high + one low; an acknowledged medium is not counted.
        $this->alert('high', 'token_reuse', $recent, $alice);
        $this->alert('low', 'report_burst', $recent, $bob);
        $this->alert('medium', 'upload_abuse', $recent, $alice, 'acknowledged');
        // 15 failed / 5 succeeded = 75 % over 20 attempts → alerte.
        for ($i = 0; $i < 15; ++$i) {
            $this->event('auth.login_failed', $recent);
        }
        for ($i = 0; $i < 5; ++$i) {
            $this->event('auth.login_succeeded', $recent, (string) $alice->getId());
        }
        // 10 rate-limited → alerte; uploads none → ok.
        for ($i = 0; $i < 10; ++$i) {
            $this->event('abuse.rate_limited', $recent, (string) $bob->getId());
        }
        // Outside the 1 h window but inside the 24 h histogram.
        $old = $now->modify('-3 hours');
        $this->event('upload.rejected', $old, (string) $bob->getId());
        // Not a notable type: never counted.
        $this->event('account.registered', $recent, (string) $bob->getId());

        $response = $this->api('GET', self::URL, $admin);
        $r = $this->json($response);

        self::assertSame('critique', $r['global'], 'worst signal = open high alert');
        self::assertSame(['suspicious', 'logins', 'abuse', 'uploads'], array_column($r['signals'], 'key'));
        [$suspicious, $logins, $abuse, $uploads] = $r['signals'];

        self::assertSame('critique', $suspicious['level']);
        self::assertSame(2, $suspicious['value']);
        self::assertSame(['high' => 1, 'medium' => 0, 'low' => 1], $suspicious['severities']);
        self::assertNull($suspicious['thresholds']);
        self::assertSame('2 alertes ouvertes', $suspicious['detail']);

        self::assertSame('alerte', $logins['level']);
        self::assertEqualsWithDelta(0.75, $logins['value'], 1e-9);
        self::assertTrue($logins['measured']);
        self::assertSame([0.2, 0.5, 0.8], $logins['thresholds']);
        self::assertNull($logins['severities']);
        self::assertSame('75 % d’échecs sur 1 h', $logins['detail']);

        self::assertSame(['alerte', 10, [1, 10, 50]], [$abuse['level'], $abuse['value'], $abuse['thresholds']]);
        self::assertSame(['ok', 0, 'aucun envoi refusé'], [$uploads['level'], $uploads['value'], $uploads['detail']]);

        self::assertCount(24, $r['hourly']);
        $hourStarts = array_column($r['hourly'], 'hourStart');
        $iRecent = array_search($this->hourOf($recent), $hourStarts, true);
        $iOld = array_search($this->hourOf($old), $hourStarts, true);
        self::assertIsInt($iRecent);
        self::assertIsInt($iOld);
        // 2 open alerts + 15 failed + 10 rate-limited (succeeded logins and registrations not notable).
        self::assertSame(27, $r['hourly'][$iRecent]['count']);
        self::assertSame('critique', $r['hourly'][$iRecent]['level']);
        self::assertSame(1, $r['hourly'][$iOld]['count']);
        self::assertSame('attention', $r['hourly'][$iOld]['level'], '1 rejected upload');
        self::assertSame(28, array_sum(array_column($r['hourly'], 'count')));
        for ($i = 1; $i < 24; ++$i) {
            self::assertSame(3600, strtotime($hourStarts[$i]) - strtotime($hourStarts[$i - 1]));
        }

        // Aggregates only: no username, email or id of anyone involved.
        $raw = $response->getContent();
        foreach (['alice', 'bob', 'boss', 'alice-secret@example.com', (string) $alice->getId(), (string) $bob->getId()] as $needle) {
            self::assertStringNotContainsString($needle, $raw);
        }
    }

    public function testLoginRateIgnoredBelowMinimumAttempts(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        for ($i = 0; $i < 19; ++$i) {
            $this->event('auth.login_failed', new \DateTimeImmutable('-1 minute'));
        }

        $r = $this->json($this->api('GET', self::URL, $admin));
        $logins = $r['signals'][1];
        self::assertSame('logins', $logins['key']);
        self::assertSame('ok', $logins['level']);
        self::assertEquals(0, $logins['value']);
        self::assertFalse($logins['measured']);
        self::assertSame('trop peu de tentatives (19 sur 1 h)', $logins['detail']);
        self::assertSame('ok', $r['global']);
    }

    /** Hour bucket of an instant, in the wire format. */
    private function hourOf(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:00:00\Z');
    }

    private function event(string $type, \DateTimeImmutable $at, ?string $actor = null): void
    {
        $this->em()->getConnection()->insert('activity_event', [
            'id' => Uuid::v7()->toRfc4122(),
            'category' => explode('.', $type)[0],
            'type' => $type,
            'actor_id' => $actor,
            'metadata' => '{}',
            'created_at' => $at->format('Y-m-d H:i:s.uP'),
        ]);
    }

    private function alert(string $severity, string $rule, \DateTimeImmutable $firstSeen, User $subject, string $status = 'open'): void
    {
        $at = $firstSeen->format('Y-m-d H:i:s.uP');
        $this->em()->getConnection()->insert('security_alert', [
            'id' => Uuid::v7()->toRfc4122(),
            'rule' => $rule,
            'severity' => $severity,
            'subject_user_id' => $subject->getId()->toRfc4122(),
            'hit_count' => 1,
            'first_seen_at' => $at,
            'last_seen_at' => $at,
            'status' => $status,
            'created_at' => $at,
        ]);
    }

    private function staff(string $username, string $role): User
    {
        $user = $this->createUser($username);
        $user->setRoles([$role]);
        $this->em()->flush();

        return $user;
    }
}
