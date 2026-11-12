<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\UnreadNotificationCountView;
use App\Repository\NotificationRepository;
use App\Security\CurrentUser;

/**
 * `GET /me/notifications/unread-count`: unread items the list would show (same omissions),
 * capped at 100; bucket `notifications`.
 *
 * @implements ProviderInterface<UnreadNotificationCountView>
 */
final readonly class UnreadNotificationCountProvider implements ProviderInterface
{
    public function __construct(private CurrentUser $currentUser, private NotificationRepository $notifications)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UnreadNotificationCountView
    {
        return new UnreadNotificationCountView($this->notifications->unreadCount($this->currentUser->require()));
    }
}
