<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `ActivityEventPage` schema (ADR 002). */
final readonly class ActivityEventPage
{
    /** @param list<ActivityEventView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
