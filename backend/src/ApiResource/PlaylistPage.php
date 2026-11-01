<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `PlaylistPage` schema. */
final readonly class PlaylistPage
{
    /** @param list<PlaylistView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
