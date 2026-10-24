<?php

declare(strict_types=1);

namespace App\Message;

/** Transport `async`. Handler: backend. Issues a 24 h token and mails the verification link. */
final readonly class SendVerificationEmail
{
    public function __construct(
        public string $userId,
    ) {
    }
}
