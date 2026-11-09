<?php

declare(strict_types=1);

namespace App\Recommendation;

use App\Entity\User;
use App\Message\RecomputeAffinity;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DeduplicateStamp;

/**
 * CONVENTIONS §8: a like, a counted listen or a completed view asks for the user's affinity
 * to be recomputed, at most once per 5 minutes per user (DeduplicateStamp, lock component).
 *
 * FB-13: best effort. Call it after the caller's transaction has committed. The dedup lock
 * (DoctrineDbalStore) and the Doctrine transport share the default connection, so the dispatch
 * runs in its own transaction (a savepoint if one is still open): a failing lock or send is
 * rolled back alone, logged as a warning, and never fails the like or the listen.
 */
final readonly class AffinityTrigger
{
    public const int DEDUPLICATE_SECONDS = 300;

    public function __construct(
        private MessageBusInterface $bus,
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function touch(User $user): void
    {
        $id = $user->getId()->toRfc4122();
        $began = false;
        try {
            $this->connection->beginTransaction();
            $began = true;
            $this->bus->dispatch(new RecomputeAffinity($id), [new DeduplicateStamp('affinity-'.$id, self::DEDUPLICATE_SECONDS)]);
            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($began && $this->connection->isTransactionActive()) {
                try {
                    $this->connection->rollBack();
                } catch (\Throwable) {
                    // the warning below carries the original cause
                }
            }
            $this->logger->warning('Affinity recompute request dropped: {reason}', ['reason' => $e->getMessage(), 'user' => $id, 'exception' => $e]);
        }
    }
}
