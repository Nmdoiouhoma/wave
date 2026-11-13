<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\State\Processor\AdminRoleProcessor;
use App\State\Processor\AdminSuspensionProcessor;
use App\State\Provider\AdminUserListProvider;
use App\State\Provider\AdminUserProvider;

/**
 * `AdminUserDetail` schema and the `/admin/users` routes (FB-28, ADR 013). ROLE_MODERATOR comes
 * from `access_control` on `^/api/v1/admin` (403 before any lookup); the role route is ROLE_ADMIN
 * through `security` with `read: false`, also checked before any lookup.
 */
#[ApiResource(
    shortName: 'AdminUser',
    operations: [
        new Get(
            uriTemplate: '/admin/users',
            name: 'listAdminUsers',
            output: AdminUserPage::class,
            security: "is_granted('ROLE_MODERATOR')",
            provider: AdminUserListProvider::class,
        ),
        new Get(
            uriTemplate: '/admin/users/{userId}',
            uriVariables: ['userId'],
            name: 'getAdminUser',
            security: "is_granted('ROLE_MODERATOR')",
            provider: AdminUserProvider::class,
        ),
        new Post(
            uriTemplate: '/admin/users/{userId}/suspension',
            uriVariables: ['userId'],
            name: 'suspendUser',
            status: 200,
            read: false,
            deserialize: false,
            security: "is_granted('ROLE_MODERATOR')",
            processor: AdminSuspensionProcessor::class,
        ),
        new Delete(
            uriTemplate: '/admin/users/{userId}/suspension',
            uriVariables: ['userId'],
            name: 'unsuspendUser',
            status: 200,
            read: false,
            deserialize: false,
            security: "is_granted('ROLE_MODERATOR')",
            processor: AdminSuspensionProcessor::class,
        ),
        new Put(
            uriTemplate: '/admin/users/{userId}/role',
            uriVariables: ['userId'],
            name: 'setUserRole',
            read: false,
            deserialize: false,
            security: "is_granted('ROLE_ADMIN')",
            processor: AdminRoleProcessor::class,
        ),
    ],
)]
final readonly class AdminUserDetailView
{
    /**
     * @param array{tracks: int, posts: int, comments: int, playlists: int, artists: int, followers: int, following: int, reportsFiled: int} $counts
     * @param array{at: string, by: ?UserSummary, note: ?string}|null                                                                        $lastSuspension
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $username,
        public string $displayName,
        public ?string $avatarUrl,
        public ?string $email,
        public bool $emailVerified,
        public string $role,
        public ?string $suspendedAt,
        public string $createdAt,
        public ?string $lastLoginAt,
        public array $counts,
        public int $openReportCount,
        public ?int $openAlertCount,
        public ?array $lastSuspension,
    ) {
    }
}
