<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `AdminUserPage` schema (ADR 002). */
final readonly class AdminUserPage
{
    /** @param list<AdminUserView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
