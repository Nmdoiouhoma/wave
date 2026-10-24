<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `TrackPage` schema (ADR 002). */
final readonly class TrackPage
{
    /** @param list<TrackView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
