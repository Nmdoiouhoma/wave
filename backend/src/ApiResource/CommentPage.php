<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `CommentPage` schema (ADR 002). */
final readonly class CommentPage
{
    /** @param list<CommentView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
