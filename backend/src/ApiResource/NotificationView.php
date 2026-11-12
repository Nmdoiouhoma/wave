<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\State\Processor\MarkNotificationsReadProcessor;
use App\State\Provider\NotificationListProvider;
use App\State\Provider\UnreadNotificationCountProvider;

/**
 * `Notification` schema and the caller's own notifications (FB-16, DATA_MODEL §8b). No route
 * takes a notification or user id: everything is scoped to the caller.
 */
#[ApiResource(
    shortName: 'Notification',
    operations: [
        new Get(uriTemplate: '/me/notifications', name: 'listMyNotifications', output: NotificationPage::class, provider: NotificationListProvider::class),
        new Get(uriTemplate: '/me/notifications/unread-count', name: 'getMyUnreadNotificationCount', output: UnreadNotificationCountView::class, provider: UnreadNotificationCountProvider::class),
        new Post(uriTemplate: '/me/notifications/read', name: 'markMyNotificationsRead', status: 204, read: false, deserialize: false, output: false, processor: MarkNotificationsReadProcessor::class),
    ],
)]
final readonly class NotificationView
{
    /** @param list<UserSummary> $actors */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $type,
        public array $actors,
        public int $actorCount,
        public ?NotificationTargetView $target,
        public bool $read,
        public string $updatedAt,
    ) {
    }
}
