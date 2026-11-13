<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\AdminDashboardProvider;

/** `AdminDashboard` schema (FB-28): `alerts` and `announcements` null for a moderator caller. */
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
     * @param array<string, int>      $users
     * @param array<string, int>      $moderation
     * @param array<string, int>      $content
     * @param array<string, int>|null $alerts
     * @param array<string, int>|null $announcements
     */
    public function __construct(
        public array $users,
        public array $moderation,
        public array $content,
        public ?array $alerts,
        public ?array $announcements,
        public string $generatedAt,
    ) {
    }
}
