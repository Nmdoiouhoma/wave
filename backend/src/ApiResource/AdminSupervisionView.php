<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\AdminSupervisionProvider;

/**
 * `SupervisionState` schema (FB-34, W-88): Zabbix-style health rollup of the admin dashboard.
 * Admin-only in substance: a moderator caller gets `global` and `hourly` null and `signals` empty
 * (ADR 013 §2, same split as `alerts` on `/admin/dashboard`). Aggregates only, no per-user data.
 */
#[ApiResource(
    shortName: 'AdminSupervision',
    operations: [
        new Get(
            uriTemplate: '/admin/dashboard/supervision',
            name: 'getAdminDashboardSupervision',
            security: "is_granted('ROLE_MODERATOR')",
            provider: AdminSupervisionProvider::class,
        ),
    ],
)]
final readonly class AdminSupervisionView
{
    /**
     * @param list<array{key: string, level: string, value: int|float, detail: string, measured: bool, severities: array{high: int, medium: int, low: int}|null, thresholds: list<int|float>|null}> $signals
     * @param list<array{hourStart: string, count: int, level: string}>|null                                                                                                                        $hourly
     */
    public function __construct(
        public ?string $global,
        public array $signals,
        public ?array $hourly,
        public string $updatedAt,
    ) {
    }
}
