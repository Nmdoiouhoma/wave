<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `CloseFriendPage` schema. */
final readonly class CloseFriendPage
{
    /** @param list<CloseFriendItem> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
