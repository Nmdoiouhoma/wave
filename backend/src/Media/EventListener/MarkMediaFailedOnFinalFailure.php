<?php

declare(strict_types=1);

namespace App\Media\EventListener;

use App\Entity\Track;
use App\Enum\FailureReason;
use App\Enum\MediaStatus;
use App\Media\LockedFind;
use App\Media\PostMediaFailure;
use App\Message\GenerateClip;
use App\Message\ProcessPostVisual;
use App\Message\ProcessTrack;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Uid\Uuid;

/**
 * A ProcessTrack that exhausted its retries (ffmpeg crash, storage outage...)
 * must not leave the track `processing` forever: mark it `processing_error`.
 * Rejected uploads were already marked failed by the handler (no-op here).
 * Same for the post handlers GenerateClip / ProcessPostVisual (W-31): the post becomes `failed`
 * and its other handler then skips.
 */
#[AsEventListener]
final class MarkMediaFailedOnFinalFailure
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();
        if ($event->willRetry()) {
            return;
        }
        if ($message instanceof GenerateClip || $message instanceof ProcessPostVisual) {
            $this->markPostFailed($message->postId, $message::class);

            return;
        }
        if (!$message instanceof ProcessTrack || !Uuid::isValid($message->trackId)) {
            return;
        }
        $trackId = Uuid::fromString($message->trackId);

        if (!$this->em->isOpen()) {
            // A DB error closed the EntityManager: nothing can be written from this process.
            $this->logger->error('ProcessTrack: entity manager closed, track left processing', ['trackId' => $message->trackId]);

            return;
        }

        try {
            $this->em->wrapInTransaction(static function (EntityManagerInterface $em) use ($trackId): void {
                $track = LockedFind::find($em, Track::class, $trackId);
                if (null !== $track && MediaStatus::Processing === $track->getStatus()) {
                    $track->markFailed(FailureReason::ProcessingError);
                }
            });
        } catch (\Throwable $e) {
            $this->logger->error('ProcessTrack: could not mark track failed after final failure', ['trackId' => $message->trackId, 'exception' => $e]);
        }
    }

    private function markPostFailed(string $postId, string $message): void
    {
        if (!Uuid::isValid($postId)) {
            return;
        }
        if (!$this->em->isOpen()) {
            $this->logger->error($message.': entity manager closed, post left processing', ['postId' => $postId]);

            return;
        }

        try {
            new PostMediaFailure($this->em)->mark(Uuid::fromString($postId), FailureReason::ProcessingError);
        } catch (\Throwable $e) {
            $this->logger->error($message.': could not mark post failed after final failure', ['postId' => $postId, 'exception' => $e]);
        }
    }
}
