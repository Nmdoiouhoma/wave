<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\State\Processor\AnnouncementProcessor;
use App\State\Provider\ActiveAnnouncementsProvider;
use App\State\Provider\AdminAnnouncementListProvider;

/**
 * `Announcement` schema (DATA_MODEL §17): ROLE_ADMIN CRUD under `/admin/announcements`, and
 * `GET /announcements` for any signed-in user (the banner). Plain text: clients render
 * `title` / `body` as text, never as HTML.
 */
#[ApiResource(
    shortName: 'Announcement',
    operations: [
        new Get(
            uriTemplate: '/admin/announcements',
            name: 'listAdminAnnouncements',
            output: AnnouncementPage::class,
            security: "is_granted('ROLE_ADMIN')",
            provider: AdminAnnouncementListProvider::class,
        ),
        new Post(
            uriTemplate: '/admin/announcements',
            name: 'createAnnouncement',
            status: 201,
            read: false,
            deserialize: false,
            security: "is_granted('ROLE_ADMIN')",
            processor: AnnouncementProcessor::class,
        ),
        new Patch(
            uriTemplate: '/admin/announcements/{announcementId}',
            uriVariables: ['announcementId'],
            name: 'updateAnnouncement',
            read: false,
            deserialize: false,
            security: "is_granted('ROLE_ADMIN')",
            processor: AnnouncementProcessor::class,
        ),
        new Delete(
            uriTemplate: '/admin/announcements/{announcementId}',
            uriVariables: ['announcementId'],
            name: 'deleteAnnouncement',
            read: false,
            deserialize: false,
            output: false,
            security: "is_granted('ROLE_ADMIN')",
            processor: AnnouncementProcessor::class,
        ),
        new Get(
            uriTemplate: '/announcements',
            name: 'listActiveAnnouncements',
            output: ActiveAnnouncementsView::class,
            security: "is_granted('ROLE_USER')",
            provider: ActiveAnnouncementsProvider::class,
        ),
    ],
)]
final readonly class AnnouncementView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $title,
        public string $body,
        public string $level,
        public string $startsAt,
        public ?string $endsAt,
        public string $updatedAt,
    ) {
    }
}
