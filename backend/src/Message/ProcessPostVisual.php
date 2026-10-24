<?php

declare(strict_types=1);

namespace App\Message;

/** Transport `media`. Handler: media-infra. Validate/transcode the post visual (image or muted video). */
final readonly class ProcessPostVisual
{
    public function __construct(
        public string $postId,
    ) {
    }
}
