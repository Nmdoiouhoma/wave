<?php

declare(strict_types=1);

namespace App\Message;

/** Transport `async`. Handler: backend. */
final readonly class SendAccountDeletedEmail
{
    public function __construct(
        public string $email,
        public string $displayName,
    ) {
    }
}
