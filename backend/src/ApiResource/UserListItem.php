<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `UserListItem` schema: UserSummary + what an inline follow button needs. */
final readonly class UserListItem
{
    public function __construct(
        public string $id,
        public string $username,
        public string $displayName,
        public ?string $avatarUrl,
        public int $followersCount,
        public bool $isMe,
        public bool $isFollowedByMe,
    ) {
    }
}
