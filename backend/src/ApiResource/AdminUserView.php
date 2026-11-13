<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `AdminUser` schema (FB-28): an item of `GET /admin/users`; `email` null for a moderator caller. */
final readonly class AdminUserView
{
    public function __construct(
        public string $id,
        public string $username,
        public string $displayName,
        public ?string $avatarUrl,
        public ?string $email,
        public bool $emailVerified,
        public string $role,
        public ?string $suspendedAt,
        public string $createdAt,
        public ?string $lastLoginAt,
    ) {
    }
}
