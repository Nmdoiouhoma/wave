<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Transport `async`. Handler: backend. Lookup, token issuance (1 h) and mail happen in
 * the worker so that the HTTP answer does not depend on the account existing (ADR 007).
 */
final readonly class SendPasswordResetEmail
{
    public function __construct(
        public string $email,
    ) {
    }
}
