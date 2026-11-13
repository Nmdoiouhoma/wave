<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\AdminDashboardProvider;

/**
 * `AdminDashboard` schema (FB-28): a moderator caller gets the `moderation` block only, every
 * other block null (ADR 013 §2, "moderation counters only", W-77 F30).
 */
#[ApiResource(
    shortName: 'AdminDashboard',
    operations: [
        new Get(
            uriTemplate: '/admin/dashboard',
            name: 'getAdminDashboard',
            security: "is_granted('ROLE_MODERATOR')",
            provider: AdminDashboardProvider::class,
        ),
    ],
)]
final readonly class AdminDashboardView
{
    /**
     * @param array<string, int>|null $users
     * @param array<string, int>      $moderation
     * @param array<string, int>|null $content
     * @param array<string, int>|null $alerts
     * @param array<string, int>|null $announcements
     */
    public function __construct(
        public ?array $users,
        public array $moderation,
        public ?array $content,
        public ?array $alerts,
        public ?array $announcements,
        public string $generatedAt,
    ) {
    }
}
