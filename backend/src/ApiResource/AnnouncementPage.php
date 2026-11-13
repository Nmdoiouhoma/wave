<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `AnnouncementPage` schema (ADR 002). */
final readonly class AnnouncementPage
{
    /** @param list<AnnouncementView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
