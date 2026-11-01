<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `UserPage` schema. */
final readonly class UserPage
{
    /** @param list<UserListItem> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
