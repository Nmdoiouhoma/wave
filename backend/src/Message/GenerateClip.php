<?php

declare(strict_types=1);

namespace App\Message;

/** Transport `media`. Handler: media-infra. Cut the processed track into `audio/clips/{postId}.m4a`. */
final readonly class GenerateClip
{
    public function __construct(
        public string $postId,
    ) {
    }
}
