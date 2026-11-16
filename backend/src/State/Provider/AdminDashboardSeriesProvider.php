<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\AdminDashboardSeriesView;
use App\Mapper\DateFormat;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Security\CurrentUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * `GET /admin/dashboard/series?range=7d|30d` (ROLE_MODERATOR, FB-31, W-82).
 *
 * Days are calendar days in Europe/Paris (bucket = local midnight to midnight, DST-aware),
 * oldest first, the last one today (partial); 7 or 30 entries, every series the same length,
 * gaps zero-filled. One grouped query on `activity_event` using the index
 * `(category, type, created_at)` (the `(category, type)` pairs are hard-coded, never user input);
 * alerts come from `security_alert.first_seen_at` (admin only). Cached 5 min per (range, role
 * level): a moderator gets `moderation` only, so the cached admin-only series never reach them.
 *
 * @implements ProviderInterface<AdminDashboardSeriesView>
 */
final readonly class AdminDashboardSeriesProvider implements ProviderInterface
{
    private const int TTL = 300;
    private const string TZ = 'Europe/Paris';

    /** (category, type) pairs per series key; the index leads on (category, type). */
    private const array ACTIVITY_TYPES = [
        'registrations' => ['account', 'account.registered'],
        'logins' => ['auth', 'auth.login_succeeded'],
        'tracks' => ['content', 'content.track_created'],
        'posts' => ['content', 'content.post_created'],
        'comments' => ['content', 'content.comment_created'],
        'rejectedUploads' => ['upload', 'upload.rejected'],
        'reports' => ['report', 'report.created'],
        'autoHidden' => ['report', 'report.auto_hidden'],
        'adminHidden' => ['admin', 'admin.content_hidden'],
    ];

    /** Series a moderator is allowed to see (ADR 013 §2). */
    private const array MODERATOR_TYPES = ['reports', 'autoHidden', 'adminHidden'];

    public function __construct(
        private CurrentUser $currentUser,
        private Connection $db,
        #[Autowire(service: 'cache.admin_dashboard')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminDashboardSeriesView
    {
        $admin = $this->currentUser->require()->isAdmin();
        $days = $this->parseRange($context['request'] ?? null);

        $level = $admin ? 'admin' : 'mod';
        $key = \sprintf('series:%dd:%s', $days, $level);

        /** @var array<string, mixed> $data */
        $data = $this->cache->get($key, fn (ItemInterface $item): array => $this->compute($item, $days, $admin));

        /** @var list<string> $dayLabels */
        $dayLabels = $data['days'];
        /** @var array<string, list<int>> $series */
        $series = $data['series'];

        return new AdminDashboardSeriesView(
            $days.'d',
            self::TZ,
            $dayLabels,
            $admin ? ['registrations' => $series['registrations'], 'logins' => $series['logins']] : null,
            $admin ? [
                'tracks' => $series['tracks'],
                'posts' => $series['posts'],
                'comments' => $series['comments'],
                'rejectedUploads' => $series['rejectedUploads'],
            ] : null,
            ['reports' => $series['reports'], 'hidden' => $series['hidden']],
            $admin ? ['high' => $series['high'], 'medium' => $series['medium'], 'low' => $series['low']] : null,
            $data['generatedAt'],
        );
    }

    /** @return int days in the window (7 or 30) */
    private function parseRange(mixed $request): int
    {
        $range = $request instanceof Request ? (string) $request->query->get('range', '7d') : '7d';

        return match ($range) {
            '7d' => 7,
            '30d' => 30,
            default => throw new ApiProblemException(ProblemCode::ValidationFailed),
        };
    }

    /**
     * @return array{days: list<string>, series: array<string, list<int>>, generatedAt: string}
     */
    private function compute(ItemInterface $item, int $days, bool $admin): array
    {
        $item->expiresAfter(self::TTL);

        $paris = new \DateTimeZone(self::TZ);
        $today = new \DateTimeImmutable('now', $paris)->setTime(0, 0, 0);
        $labels = [];
        for ($i = $days - 1; $i >= 0; --$i) {
            $labels[] = $today->modify("-{$i} days")->format('Y-m-d');
        }
        $index = array_flip($labels);
        $lower = $today->modify('-'.($days - 1).' days')->format('Y-m-d H:i:sP');

        $wanted = $admin ? array_keys(self::ACTIVITY_TYPES) : self::MODERATOR_TYPES;
        $byType = $this->activityCounts($wanted, $lower, $index, \count($labels));

        $series = [];
        foreach ($wanted as $k) {
            $series[$k] = $byType[$k];
        }
        // moderation.hidden = report.auto_hidden + admin.content_hidden
        $hidden = [];
        foreach ($byType['autoHidden'] as $i => $v) {
            $hidden[] = $v + $byType['adminHidden'][$i];
        }
        $series['hidden'] = $hidden;

        if ($admin) {
            foreach ($this->alertCounts($lower, $index, \count($labels)) as $sev => $counts) {
                $series[$sev] = $counts;
            }
        }

        return ['days' => $labels, 'series' => $series, 'generatedAt' => DateFormat::format(new \DateTimeImmutable())];
    }

    /**
     * One grouped query on `activity_event`. Returns one zero-filled series per wanted key.
     *
     * @param list<string>      $wanted
     * @param array<string,int> $index  local date => position
     *
     * @return array<string, list<int>>
     */
    private function activityCounts(array $wanted, string $lower, array $index, int $len): array
    {
        $typeToKey = [];
        $pairs = [];
        foreach ($wanted as $k) {
            [$cat, $type] = self::ACTIVITY_TYPES[$k];
            $typeToKey[$type] = $k;
            $pairs[] = \sprintf("('%s','%s')", $cat, $type);
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT type,
                    (timezone(:tz, created_at))::date::text AS day,
                    count(*) AS c,
                    count(DISTINCT actor_id) AS d
             FROM activity_event
             WHERE (category, type) IN ('.implode(',', $pairs).')
               AND created_at >= :lower::timestamptz
             GROUP BY type, day',
            ['tz' => self::TZ, 'lower' => $lower],
        );

        /** @var array<string, array<int, int>> $acc key => (position => count) */
        $acc = [];
        foreach ($rows as $row) {
            $key = $typeToKey[(string) $row['type']] ?? null;
            $pos = $index[(string) $row['day']] ?? null;
            if (null === $key || null === $pos) {
                continue;
            }
            // logins = distinct users with a login that day; every other series = row count.
            $acc[$key][$pos] = (int) ('logins' === $key ? $row['d'] : $row['c']);
        }

        $out = [];
        foreach ($wanted as $k) {
            $out[$k] = self::series($acc[$k] ?? [], $len);
        }

        return $out;
    }

    /**
     * Zero-filled list of $len entries, filled from a (position => count) map.
     *
     * @param array<int, int> $counts
     *
     * @return list<int>
     */
    private static function series(array $counts, int $len): array
    {
        $out = [];
        for ($i = 0; $i < $len; ++$i) {
            $out[] = $counts[$i] ?? 0;
        }

        return $out;
    }

    /**
     * @param array<string,int> $index
     *
     * @return array<string, list<int>> severity => zero-filled series
     */
    private function alertCounts(string $lower, array $index, int $len): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT severity,
                    (timezone(:tz, first_seen_at))::date::text AS day,
                    count(*) AS c
             FROM security_alert
             WHERE first_seen_at >= :lower::timestamptz
             GROUP BY severity, day',
            ['tz' => self::TZ, 'lower' => $lower],
        );

        /** @var array<string, array<int, int>> $acc severity => (position => count) */
        $acc = [];
        foreach ($rows as $row) {
            $sev = (string) $row['severity'];
            $pos = $index[(string) $row['day']] ?? null;
            if (null === $pos) {
                continue;
            }
            $acc[$sev][$pos] = (int) $row['c'];
        }

        return [
            'high' => self::series($acc['high'] ?? [], $len),
            'medium' => self::series($acc['medium'] ?? [], $len),
            'low' => self::series($acc['low'] ?? [], $len),
        ];
    }
}
