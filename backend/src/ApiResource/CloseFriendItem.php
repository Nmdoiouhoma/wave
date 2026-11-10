<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `CloseFriendItem` schema: UserSummary + membership flag. */
final readonly class CloseFriendItem
{
    public function __construct(
        public string $id,
        public string $username,
        public string $displayName,
        public ?string $avatarUrl,
        public bool $isCloseFriend,
    ) {
    }
}
