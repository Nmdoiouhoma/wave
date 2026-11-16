<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\User;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\Uid\Uuid;

/** W-82: `GET /admin/dashboard/series?range=7d|30d` (FB-31, ADR 013 §2, DATA_MODEL §18). */
final class AdminDashboardSeriesTest extends WaveApiTestCase
{
    private const string URL = '/api/v1/admin/dashboard/series';

    public function testAccessSplitAndBadRange(): void
    {
        $user = $this->createUser('plain');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');

        $this->assertProblem($this->api('GET', self::URL), 401, 'unauthenticated');
        $this->assertProblem($this->api('GET', self::URL, $user), 403, 'forbidden');
        $this->assertProblem($this->api('GET', self::URL.'?range=1y', $mod), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', self::URL.'?range=7', $mod), 422, 'validation_failed');
    }

    public function testDefaultRangeShapeAndModeratorSeesModerationOnly(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'))->format('Y-m-d');

        $full = $this->json($this->api('GET', self::URL, $admin)); // default 7d
        self::assertSame('7d', $full['range']);
        self::assertSame('Europe/Paris', $full['timezone']);
        self::assertCount(7, $full['days']);
        self::assertSame($today, $full['days'][6], 'last day = today Paris');
        foreach (['registrations', 'logins'] as $k) {
            self::assertCount(7, $full['activity'][$k]);
        }
        foreach (['tracks', 'posts', 'comments', 'rejectedUploads'] as $k) {
            self::assertCount(7, $full['content'][$k]);
        }
        foreach (['reports', 'hidden'] as $k) {
            self::assertCount(7, $full['moderation'][$k]);
        }
        foreach (['high', 'medium', 'low'] as $k) {
            self::assertCount(7, $full['alerts'][$k]);
        }

        $forMod = $this->json($this->api('GET', self::URL, $mod));
        self::assertNull($forMod['activity'], 'ADR 013: moderation only');
        self::assertNull($forMod['content']);
        self::assertNull($forMod['alerts']);
        self::assertSame($full['moderation'], $forMod['moderation']);
    }

    public function testCountsZeroFillAndDistinctLogins(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'))->setTime(12, 0);

        // 2 registrations today, 3 logins but only 2 distinct users today.
        $this->event('account.registered', $today, (string) $alice->getId());
        $this->event('account.registered', $today, (string) $bob->getId());
        $this->event('auth.login_succeeded', $today, (string) $alice->getId());
        $this->event('auth.login_succeeded', $today, (string) $alice->getId());
        $this->event('auth.login_succeeded', $today, (string) $bob->getId());
        // hidden = report.auto_hidden + admin.content_hidden.
        $this->event('report.auto_hidden', $today, (string) $alice->getId());
        $this->event('admin.content_hidden', $today, (string) $admin->getId());
        $this->alert('high', $today, $alice);

        $r = $this->json($this->api('GET', self::URL.'?range=30d', $admin));
        self::assertSame('30d', $r['range']);
        self::assertCount(30, $r['days']);

        self::assertSame(2, $r['activity']['registrations'][29]);
        self::assertSame(2, $r['activity']['logins'][29], 'distinct users, not event count');
        self::assertSame(2, $r['moderation']['hidden'][29], 'auto_hidden + content_hidden');
        self::assertSame(1, $r['alerts']['high'][29]);
        self::assertSame(0, $r['activity']['registrations'][0], 'gap zero-filled');
        self::assertSame(array_sum($r['moderation']['reports']), 0);
    }

    public function testEventAt2330UtcLandsOnNextLocalDayAcrossDst(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $paris = new \DateTimeZone('Europe/Paris');
        $utc = new \DateTimeZone('UTC');
        $today = new \DateTimeImmutable('now', $paris)->setTime(0, 0);
        $windowFirst = $today->modify('-29 days');

        // Prefer a local day that is itself a DST-change day within the 30-day window; else a recent day.
        $dLocal = $today->modify('-2 days');
        foreach (\array_slice($paris->getTransitions($windowFirst->getTimestamp(), $today->getTimestamp()), 1) as $t) {
            $cand = new \DateTimeImmutable('@'.$t['ts'])->setTimezone($paris)->setTime(0, 0);
            if ($cand >= $windowFirst && $cand < $today) {
                $dLocal = $cand;
                break;
            }
        }

        // 23:30 UTC on the UTC day before D → 00:30/01:30 Paris on D → local bucket D, never D-1.
        $eventUtc = new \DateTimeImmutable($dLocal->modify('-1 day')->format('Y-m-d').' 23:30:00', $utc);
        $this->event('account.registered', $eventUtc, (string) $admin->getId());

        $r = $this->json($this->api('GET', self::URL.'?range=30d', $admin));
        $days = $r['days'];
        $idx = array_search($dLocal->format('Y-m-d'), $days, true);
        self::assertIsInt($idx);
        self::assertSame(1, $r['activity']['registrations'][$idx], 'lands on local day D');
        if ($idx > 0) {
            self::assertSame(0, $r['activity']['registrations'][$idx - 1], 'not the UTC day D-1');
        }
    }

    private function event(string $type, \DateTimeImmutable $at, ?string $actor = null): void
    {
        $this->em()->getConnection()->insert('activity_event', [
            'id' => Uuid::v7()->toRfc4122(),
            'category' => explode('.', $type)[0],
            'type' => $type,
            'actor_id' => $actor,
            'metadata' => '{}',
            'created_at' => $at->format('Y-m-d H:i:sP'),
        ]);
    }

    private function alert(string $severity, \DateTimeImmutable $firstSeen, User $subject): void
    {
        $at = $firstSeen->format('Y-m-d H:i:s.uP');
        $this->em()->getConnection()->insert('security_alert', [
            'id' => Uuid::v7()->toRfc4122(),
            'rule' => 'token_reuse',
            'severity' => $severity,
            'subject_user_id' => $subject->getId()->toRfc4122(),
            'hit_count' => 1,
            'first_seen_at' => $at,
            'last_seen_at' => $at,
            'status' => 'open',
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
