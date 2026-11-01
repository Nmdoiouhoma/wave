<?php

declare(strict_types=1);

namespace App\Recommendation;

use App\Entity\User;
use App\Message\RecomputeAffinity;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

/**
 * CONVENTIONS §8: a like, a counted listen or a completed view asks for the user's affinity
 * to be recomputed, at most once per 5 minutes per user (DeduplicateStamp, lock component).
 */
final readonly class AffinityTrigger
{
    public const int DEDUPLICATE_SECONDS = 300;

    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function touch(User $user): void
    {
        $id = $user->getId()->toRfc4122();
        $this->bus->dispatch(new RecomputeAffinity($id), [new DeduplicateStamp('affinity-'.$id, self::DEDUPLICATE_SECONDS)]);
    }
}
