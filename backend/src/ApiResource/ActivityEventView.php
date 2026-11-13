<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\AdminAuditLogProvider;
use App\State\Provider\UserActivityProvider;

/**
 * `ActivityEvent` schema (DATA_MODEL §15) and the ROLE_ADMIN journal routes (ADR 013). The
 * providers also check ROLE_ADMIN themselves before any lookup: a moderator gets 403, never a
 * 404 that would tell whether the id exists.
 */
#[ApiResource(
    shortName: 'ActivityEvent',
    operations: [
        new Get(
            uriTemplate: '/admin/users/{userId}/activity',
            uriVariables: ['userId'],
            name: 'listUserActivity',
            output: ActivityEventPage::class,
            security: "is_granted('ROLE_ADMIN')",
            provider: UserActivityProvider::class,
        ),
        new Get(
            uriTemplate: '/admin/audit-log',
            name: 'listAdminAuditLog',
            output: ActivityEventPage::class,
            security: "is_granted('ROLE_ADMIN')",
            provider: AdminAuditLogProvider::class,
        ),
    ],
)]
final readonly class ActivityEventView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $category,
        public string $type,
        public ?UserSummary $actor,
        public ?UserSummary $subjectUser,
        public ?string $targetType,
        public ?string $targetId,
        public ?string $ipPrefix,
        public ?string $ipHash,
        public ?string $userAgent,
        public object $metadata,
        public string $createdAt,
    ) {
    }
}
