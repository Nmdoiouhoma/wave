<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `UnreadNotificationCount` schema. */
final readonly class UnreadNotificationCountView
{
    public function __construct(public int $unreadCount)
    {
    }
}
