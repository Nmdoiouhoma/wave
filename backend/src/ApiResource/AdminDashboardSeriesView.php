<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\AdminDashboardSeriesProvider;

/**
 * `AdminDashboardSeries` schema (FB-31, W-82): daily counts for the admin charts, one call for
 * every series. Days are calendar days in Europe/Paris, oldest first, the last one today.
 * A moderator caller gets the `moderation` block only, `activity`, `content` and `alerts` null
 * (ADR 013 §2). Cached 5 min server-side per (range, role level).
 */
#[ApiResource(
    shortName: 'AdminDashboardSeries',
    operations: [
        new Get(
            uriTemplate: '/admin/dashboard/series',
            name: 'getAdminDashboardSeries',
            security: "is_granted('ROLE_MODERATOR')",
            provider: AdminDashboardSeriesProvider::class,
        ),
    ],
)]
final readonly class AdminDashboardSeriesView
{
    /**
     * @param list<string>                                                                                     $days
     * @param array{registrations: list<int>, logins: list<int>}|null                                          $activity
     * @param array{tracks: list<int>, posts: list<int>, comments: list<int>, rejectedUploads: list<int>}|null $content
     * @param array{reports: list<int>, hidden: list<int>}                                                     $moderation
     * @param array{high: list<int>, medium: list<int>, low: list<int>}|null                                   $alerts
     */
    public function __construct(
        public string $range,
        public string $timezone,
        public array $days,
        public ?array $activity,
        public ?array $content,
        public array $moderation,
        public ?array $alerts,
        public string $generatedAt,
    ) {
    }
}
