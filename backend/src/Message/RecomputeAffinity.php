<?php

declare(strict_types=1);

namespace App\Message;

/** Transport `async`, dispatched with DeduplicateStamp("affinity-{userId}", 300 s). Handler: backend (ADR 005). */
final readonly class RecomputeAffinity
{
    public function __construct(
        public string $userId,
    ) {
    }
}
