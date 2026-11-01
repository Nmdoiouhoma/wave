<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `PlaylistItemPage` schema. */
final readonly class PlaylistItemPage
{
    /** @param list<PlaylistItemView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
    ) {
    }
}
