<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\State\Processor\AdminContentDeletionProcessor;
use App\State\Provider\AdminContentListProvider;

/**
 * `AdminContentItem` schema and the `/admin/content` routes (FB-28, ADR 013). Deletion is
 * ROLE_ADMIN (`security` with `read: false`: a moderator gets 403 before any lookup).
 */
#[ApiResource(
    shortName: 'AdminContent',
    operations: [
        new Get(
            uriTemplate: '/admin/content',
            name: 'listAdminContent',
            output: AdminContentPage::class,
            security: "is_granted('ROLE_MODERATOR')",
            provider: AdminContentListProvider::class,
        ),
        new Post(
            uriTemplate: '/admin/content/{contentType}/{contentId}/deletion',
            uriVariables: ['contentType', 'contentId'],
            name: 'deleteAdminContent',
            status: 204,
            read: false,
            deserialize: false,
            output: false,
            security: "is_granted('ROLE_ADMIN')",
            processor: AdminContentDeletionProcessor::class,
        ),
    ],
)]
final readonly class AdminContentItemView
{
    public function __construct(
        public string $type,
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $label,
        public ?string $imageUrl,
        public UserSummary $owner,
        public string $moderation,
        public ?string $status,
        public ?string $visibility,
        public int $openReportCount,
        public string $createdAt,
    ) {
    }
}
