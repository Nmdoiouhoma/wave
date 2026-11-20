<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `HashtagPage` schema. */
final readonly class HashtagPage
{
    /** @param list<HashtagView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
