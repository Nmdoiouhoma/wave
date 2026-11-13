<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RecomputeAffinity;
use App\Message\RecomputeAffinityAll;
use App\Recommendation\AffinityRecomputer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/** CONVENTIONS §8: one `RecomputeAffinity` per user active in the last 30 days. */
#[AsMessageHandler]
final readonly class RecomputeAffinityAllHandler
{
    public function __construct(private AffinityRecomputer $affinity, private MessageBusInterface $bus)
    {
    }

    public function __invoke(RecomputeAffinityAll $message): void
    {
        foreach ($this->affinity->activeUserIds() as $id) {
            $this->bus->dispatch(new RecomputeAffinity($id));
        }
    }
}
