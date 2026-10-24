<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `UserSummary` schema (embedded, not a resource). */
final readonly class UserSummary
{
    public function __construct(
        public string $id,
        public string $username,
        public string $displayName,
        public ?string $avatarUrl,
    ) {
    }
}
