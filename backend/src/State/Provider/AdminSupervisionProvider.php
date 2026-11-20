<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Admin\SupervisionThresholds as T;
use App\ApiResource\AdminSupervisionView;
use App\Enum\SupervisionLevel;
use App\Mapper\DateFormat;
use App\Security\CurrentUser;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * `GET /admin/dashboard/supervision` (ROLE_MODERATOR guard, admin-only content; FB-34, W-88).
 *
 * Reads only already-collected data (DATA_MODEL §15/§16): open `security_alert` rows
 * (`idx_security_alert_status`) and the `auth.*` / `abuse.*` / `upload.*` journal of
 * `activity_event` (`idx_activity_event_type_created`; the `(category, type)` pairs are
 * hard-coded, never user input). Three queries, cached 30 s; the moderator shape is built without
 * touching the cache or the database, so the cached admin payload never reaches a moderator.
 *
 * Hourly buckets are absolute hours (Europe/Paris offsets are whole hours, so a UTC hour is a
 * Paris hour): across a DST change the 24 buckets stay 24 consecutive hours, the repeated local
 * 02 h of the autumn change being two distinct buckets.
 *
 * @implements ProviderInterface<AdminSupervisionView>
 */
final readonly class AdminSupervisionProvider implements ProviderInterface
{
    private const string KEY = 'admin_supervision';
    private const int TTL = 30;
    public const int HOURS = 24;

    /** (category, type) pairs read from the journal. */
    private const array TYPES = [
        ['auth', 'auth.login_failed'],
        ['auth', 'auth.login_succeeded'],
        ['abuse', 'abuse.rate_limited'],
        ['upload', 'upload.rejected'],
    ];

    public function __construct(
        private CurrentUser $currentUser,
        private Connection $db,
        private ClockInterface $clock,
        #[Autowire(service: 'cache.admin_dashboard')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminSupervisionView
    {
        if (!$this->currentUser->require()->isAdmin()) {
            return new AdminSupervisionView(null, [], null, DateFormat::format($this->clock->now()));
        }

        /** @var array{global: string, signals: list<array{key: string, level: string, value: int|float, detail: string, measured: bool, severities: array{high: int, medium: int, low: int}|null, thresholds: list<int|float>|null}>, hourly: list<array{hourStart: string, count: int, level: string}>, updatedAt: string} $data */
        $data = $this->cache->get(self::KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL);

            return $this->compute($this->clock->now());
        });

        return new AdminSupervisionView($data['global'], $data['signals'], $data['hourly'], $data['updatedAt']);
    }

    /**
     * @return array{global: string, signals: list<array{key: string, level: string, value: int|float, detail: string, measured: bool, severities: array{high: int, medium: int, low: int}|null, thresholds: list<int|float>|null}>, hourly: list<array{hourStart: string, count: int, level: string}>, updatedAt: string}
     */
    public function compute(\DateTimeImmutable $now): array
    {
        $signals = $this->signals($now);
        $global = SupervisionLevel::worst(...array_map(
            static fn (array $s): SupervisionLevel => SupervisionLevel::from($s['level']),
            $signals,
        ));

        return [
            'global' => $global->value,
            'signals' => $signals,
            'hourly' => $this->hourly($now),
            'updatedAt' => DateFormat::format($now),
        ];
    }

    /**
     * Start of each of the last 24 hourly buckets, oldest first, the last one = the current hour.
     *
     * @return list<\DateTimeImmutable> UTC instants
     */
    public static function hourStarts(\DateTimeImmutable $now): array
    {
        $current = $now->setTimezone(new \DateTimeZone('UTC'));
        $current = $current->setTime((int) $current->format('H'), 0, 0);
        $out = [];
        for ($i = self::HOURS - 1; $i >= 0; --$i) {
            $out[] = $current->sub(new \DateInterval('PT'.$i.'H'));
        }

        return $out;
    }

    /**
     * @return list<array{key: string, level: string, value: int|float, detail: string, measured: bool, severities: array{high: int, medium: int, low: int}|null, thresholds: list<int|float>|null}>
     */
    private function signals(\DateTimeImmutable $now): array
    {
        $sev = ['high' => 0, 'medium' => 0, 'low' => 0];
        foreach ($this->db->fetchAllKeyValue(
            "SELECT severity, count(*) FROM security_alert WHERE status = 'open' GROUP BY severity",
        ) as $severity => $count) {
            if (\array_key_exists((string) $severity, $sev)) {
                $sev[(string) $severity] = (int) $count;
            }
        }
        $open = array_sum($sev);
        $suspicious = SupervisionLevel::worst(...array_map(
            static fn (string $s): SupervisionLevel => SupervisionLevel::fromSeverity($s),
            array_keys(array_filter($sev)),
        ));

        $since = $now->sub(new \DateInterval('PT'.T::WINDOW_SECONDS.'S'));
        $byType = $this->db->fetchAllKeyValue(
            'SELECT type, count(*) FROM activity_event
             WHERE (category, type) IN ('.self::pairs().') AND created_at >= :since::timestamptz
             GROUP BY type',
            ['since' => $since->format('Y-m-d H:i:s.uP')],
        );
        $n = static fn (string $type): int => (int) ($byType[$type] ?? 0);
        $failed = $n('auth.login_failed');
        $succeeded = $n('auth.login_succeeded');
        $abuse = $n('abuse.rate_limited');
        $uploads = $n('upload.rejected');

        $measured = T::loginMeasured($failed, $succeeded);
        $rate = round(T::loginRate($failed, $succeeded), 4);

        return [
            [
                'key' => 'suspicious',
                'level' => $suspicious->value,
                'value' => $open,
                'detail' => match ($open) {
                    0 => 'aucune alerte ouverte',
                    1 => '1 alerte ouverte',
                    default => $open.' alertes ouvertes',
                },
                'measured' => true,
                'severities' => $sev,
                'thresholds' => null,
            ],
            [
                'key' => 'logins',
                'level' => T::loginLevel($failed, $succeeded)->value,
                'value' => $rate,
                'detail' => $measured
                    ? \sprintf('%d %% d’échecs sur 1 h', (int) round($rate * 100))
                    : \sprintf('trop peu de tentatives (%d sur 1 h)', $failed + $succeeded),
                'measured' => $measured,
                'severities' => null,
                'thresholds' => T::LOGIN_RATE_STEPS,
            ],
            [
                'key' => 'abuse',
                'level' => T::abuseLevel($abuse)->value,
                'value' => $abuse,
                'detail' => match ($abuse) {
                    0 => 'aucune limite atteinte',
                    1 => '1 limite atteinte sur 1 h',
                    default => $abuse.' limites atteintes sur 1 h',
                },
                'measured' => true,
                'severities' => null,
                'thresholds' => T::ABUSE_STEPS,
            ],
            [
                'key' => 'uploads',
                'level' => T::uploadLevel($uploads)->value,
                'value' => $uploads,
                'detail' => match ($uploads) {
                    0 => 'aucun envoi refusé',
                    1 => '1 envoi refusé sur 1 h',
                    default => $uploads.' envois refusés sur 1 h',
                },
                'measured' => true,
                'severities' => null,
                'thresholds' => T::UPLOAD_STEPS,
            ],
        ];
    }

    /**
     * @return list<array{hourStart: string, count: int, level: string}>
     */
    private function hourly(\DateTimeImmutable $now): array
    {
        $starts = self::hourStarts($now);
        $lower = $starts[0]->format('Y-m-d H:i:sP');
        $bucket = static fn (string $col): string => "to_char(date_trunc('hour', {$col} AT TIME ZONE 'UTC'), 'YYYY-MM-DD HH24:00')";

        /** @var array<string, array<string, int>> $acc 'Y-m-d H:00' UTC => (type or severity => count) */
        $acc = [];
        foreach ($this->db->fetchAllAssociative(
            'SELECT type AS k, '.$bucket('created_at').' AS h, count(*) AS c FROM activity_event
             WHERE (category, type) IN ('.self::pairs().') AND created_at >= :lower::timestamptz
             GROUP BY 1, 2',
            ['lower' => $lower],
        ) as $row) {
            $acc[(string) $row['h']][(string) $row['k']] = (int) $row['c'];
        }
        foreach ($this->db->fetchAllAssociative(
            "SELECT severity AS k, {$bucket('first_seen_at')} AS h, count(*) AS c FROM security_alert
             WHERE status = 'open' AND first_seen_at >= :lower::timestamptz
             GROUP BY 1, 2",
            ['lower' => $lower],
        ) as $row) {
            $acc[(string) $row['h']]['alert.'.$row['k']] = (int) $row['c'];
        }

        $out = [];
        foreach ($starts as $start) {
            $c = $acc[$start->format('Y-m-d H:00')] ?? [];
            $get = static fn (string $k): int => $c[$k] ?? 0;
            $alertLevels = [];
            foreach (['high', 'medium', 'low'] as $s) {
                if ($get('alert.'.$s) > 0) {
                    $alertLevels[] = SupervisionLevel::fromSeverity($s);
                }
            }
            $level = SupervisionLevel::worst(
                T::loginLevel($get('auth.login_failed'), $get('auth.login_succeeded')),
                T::abuseLevel($get('abuse.rate_limited')),
                T::uploadLevel($get('upload.rejected')),
                ...$alertLevels,
            );
            $out[] = [
                'hourStart' => DateFormat::format($start),
                'count' => $get('alert.high') + $get('alert.medium') + $get('alert.low')
                    + $get('auth.login_failed') + $get('abuse.rate_limited') + $get('upload.rejected'),
                'level' => $level->value,
            ];
        }

        return $out;
    }

    private static function pairs(): string
    {
        return implode(',', array_map(static fn (array $p): string => \sprintf("('%s','%s')", $p[0], $p[1]), self::TYPES));
    }
}
