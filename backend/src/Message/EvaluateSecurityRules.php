<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Dispatched by ActivityJournal after a journaled event of a watched type (DATA_MODEL §15-16),
 * transport `async`. Handler: backend (EvaluateSecurityRulesHandler).
 */
final readonly class EvaluateSecurityRules
{
    public function __construct(public string $eventId)
    {
    }
}
