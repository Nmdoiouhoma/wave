<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `SecurityAlertPage` schema (ADR 002). */
final readonly class SecurityAlertPage
{
    /** @param list<SecurityAlertView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
