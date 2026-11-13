<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RecomputeAffinity;
use App\Recommendation\AffinityRecomputer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/** ADR 005: rewrite `user_affinity` for one user (an unknown/deleted user just yields no rows). */
#[AsMessageHandler]
final readonly class RecomputeAffinityHandler
{
    public function __construct(private AffinityRecomputer $affinity)
    {
    }

    public function __invoke(RecomputeAffinity $message): void
    {
        if (!Uuid::isValid($message->userId)) {
            return;
        }
        $this->affinity->recompute(Uuid::fromString($message->userId));
    }
}
