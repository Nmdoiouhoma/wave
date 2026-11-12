<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `NotificationPage` schema. */
final readonly class NotificationPage
{
    /** @param list<NotificationView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
