<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `ReportPage` schema (ADR 002). */
final readonly class ReportPage
{
    /** @param list<ReportView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
