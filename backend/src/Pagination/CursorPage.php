<?php

declare(strict_types=1);

namespace App\Pagination;

/**
 * Response shape of every list: `{items, nextCursor}` (ADR 002).
 *
 * @template T of object
 */
final readonly class CursorPage
{
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
