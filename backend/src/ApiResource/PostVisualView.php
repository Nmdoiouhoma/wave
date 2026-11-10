<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `PostVisual` schema. */
final readonly class PostVisualView
{
    public function __construct(
        public string $type,
        public string $url,
        public ?string $posterUrl,
    ) {
    }
}
