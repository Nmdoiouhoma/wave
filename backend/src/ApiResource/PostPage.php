<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `PostPage` schema (ADR 002). */
final readonly class PostPage
{
    /** @param list<PostView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
