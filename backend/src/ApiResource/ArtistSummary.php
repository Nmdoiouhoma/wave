<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `ArtistSummary` schema (embedded, not a resource). */
final readonly class ArtistSummary
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $name,
        public ?string $avatarUrl,
    ) {
    }
}
