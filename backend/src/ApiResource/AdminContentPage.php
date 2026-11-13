<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `AdminContentPage` schema (ADR 002). */
final readonly class AdminContentPage
{
    /** @param list<AdminContentItemView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
