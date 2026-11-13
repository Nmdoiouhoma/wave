<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Admin\AdminUserQuery;
use App\ApiResource\AdminDashboardView;
use App\Mapper\DateFormat;
use App\Security\CurrentUser;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * `GET /admin/dashboard` (ROLE_MODERATOR, FB-28). One aggregate query cached 60 s and shared by
 * every caller; a moderator gets the moderation counters only (ADR 013 §2): `users`, `content`,
 * `alerts` and `announcements` are nulled after the cache read, so the cached value never
 * depends on the caller.
 *
 * @implements ProviderInterface<AdminDashboardView>
 */
final readonly class AdminDashboardProvider implements ProviderInterface
{
    private const string KEY = 'admin_dashboard';
    private const int TTL = 60;

    public function __construct(
        private CurrentUser $currentUser,
        private Connection $db,
        #[Autowire(service: 'cache.admin_dashboard')]
        private CacheInterface $cache,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminDashboardView
    {
        $admin = $this->currentUser->require()->isAdmin();
        /** @var array{generatedAt: string, row: array<string, int|string>} $data */
        $data = $this->cache->get(self::KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL);

            return ['generatedAt' => DateFormat::format(new \DateTimeImmutable()), 'row' => $this->compute()];
        });
        $n = static fn (string $key): int => (int) $data['row'][$key];

        return new AdminDashboardView(
            $admin ? [
                'total' => $n('users_total'),
                'new24h' => $n('users_new24h'),
                'new7d' => $n('users_new7d'),
                'suspended' => $n('users_suspended'),
                'unverified' => $n('users_unverified'),
                'moderators' => $n('users_moderators'),
                'admins' => $n('users_admins'),
            ] : null,
            [
                'openReportTargets' => $n('open_targets'),
                'openReports' => $n('open_reports'),
                'hiddenContent24h' => $n('hidden24h'),
            ],
            $admin ? [
                'tracks24h' => $n('tracks24h'),
                'posts24h' => $n('posts24h'),
                'comments24h' => $n('comments24h'),
                'failedUploads24h' => $n('failed_uploads24h'),
            ] : null,
            $admin ? [
                'open' => $n('alerts_high') + $n('alerts_medium') + $n('alerts_low'),
                'openHigh' => $n('alerts_high'),
                'openMedium' => $n('alerts_medium'),
                'openLow' => $n('alerts_low'),
            ] : null,
            $admin ? ['active' => $n('ann_active'), 'scheduled' => $n('ann_scheduled')] : null,
            $data['generatedAt'],
        );
    }

    /** @return array<string, int|string> */
    private function compute(): array
    {
        $admin = AdminUserQuery::HAS_ADMIN;
        $moderator = AdminUserQuery::HAS_MODERATOR;
        $day = "now() - interval '24 hours'";
        $hidden = "type = 'report.auto_hidden' OR type = 'admin.content_hidden'
            OR (type = 'admin.report_decided' AND metadata->>'action' = 'hide')";

        /* @var array<string, int|string> */
        return $this->db->fetchAssociative(
            "SELECT
                (SELECT count(*) FROM app_user) AS users_total,
                (SELECT count(*) FROM app_user WHERE created_at >= {$day}) AS users_new24h,
                (SELECT count(*) FROM app_user WHERE created_at >= now() - interval '7 days') AS users_new7d,
                (SELECT count(*) FROM app_user WHERE suspended_at IS NOT NULL) AS users_suspended,
                (SELECT count(*) FROM app_user WHERE suspended_at IS NULL AND email_verified_at IS NULL) AS users_unverified,
                (SELECT count(*) FROM app_user u WHERE {$moderator} AND NOT {$admin}) AS users_moderators,
                (SELECT count(*) FROM app_user u WHERE {$admin}) AS users_admins,
                (SELECT count(DISTINCT (target_type, target_id)) FROM report WHERE status = 'open') AS open_targets,
                (SELECT count(*) FROM report WHERE status = 'open') AS open_reports,
                (SELECT count(*) FROM activity_event WHERE created_at >= {$day} AND ({$hidden})) AS hidden24h,
                (SELECT count(*) FROM track WHERE created_at >= {$day}) AS tracks24h,
                (SELECT count(*) FROM post WHERE created_at >= {$day}) AS posts24h,
                (SELECT count(*) FROM comment WHERE created_at >= {$day}) AS comments24h,
                (SELECT count(*) FROM activity_event WHERE created_at >= {$day} AND type = 'upload.rejected') AS failed_uploads24h,
                (SELECT count(*) FROM security_alert WHERE status = 'open' AND severity = 'high') AS alerts_high,
                (SELECT count(*) FROM security_alert WHERE status = 'open' AND severity = 'medium') AS alerts_medium,
                (SELECT count(*) FROM security_alert WHERE status = 'open' AND severity = 'low') AS alerts_low,
                (SELECT count(*) FROM announcement WHERE starts_at <= now() AND ends_at > now()) AS ann_active,
                (SELECT count(*) FROM announcement WHERE starts_at > now()) AS ann_scheduled",
        ) ?: [];
    }
}
