<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `ReportTargetPage` schema (ADR 002). */
final readonly class ReportTargetPage
{
    /** @param list<ReportTargetView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
