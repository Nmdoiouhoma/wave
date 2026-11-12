<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `NotificationTarget` schema (embedded): the recipient's own track, playlist or post. */
final readonly class NotificationTargetView
{
    public function __construct(
        public string $type,
        public string $id,
        public string $label,
        public ?string $imageUrl,
    ) {
    }
}
