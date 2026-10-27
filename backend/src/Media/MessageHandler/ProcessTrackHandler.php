<?php

declare(strict_types=1);

namespace App\Media\MessageHandler;

use App\Entity\Track;
use App\Enum\FailureReason;
use App\Enum\MediaStatus;
use App\Media\Exception\MediaRejected;
use App\Media\LockedFind;
use App\Media\MediaKeys;
use App\Media\TempFiles;
use App\Media\TrackAudioProcessor;
use App\Message\ProcessTrack;
use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Quarantined upload -> validated, loudness-normalized `audio/tracks/{id}.m4a`
 * (CONVENTIONS §8, DATA_MODEL §14).
 *
 * Idempotent: a redelivered message for a track that is gone or no longer
 * `processing` is a no-op. Rejected uploads mark the track failed and the
 * message is acknowledged (FB-10: never an error, never in `failed`);
 * infrastructure failures are retried, and the final one marks the track
 * `processing_error` (MarkMediaFailedOnFinalFailure).
 */
#[AsMessageHandler]
final class ProcessTrackHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ObjectStorage $storage,
        private readonly TrackAudioProcessor $audio,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessTrack $message): void
    {
        if (!Uuid::isValid($message->trackId)) {
            // Malformed message: nothing to retry, nothing to mark. Acknowledge.
            $this->logger->error('ProcessTrack: invalid track id, dropped', ['trackId' => $message->trackId]);

            return;
        }
        $trackId = Uuid::fromString($message->trackId);
        $context = ['trackId' => $trackId->toRfc4122()];

        $track = $this->em->find(Track::class, $trackId);
        if (null === $track || MediaStatus::Processing !== $track->getStatus()) {
            $this->logger->info('ProcessTrack: track gone or not processing, skipped', $context);

            return;
        }

        $rawKey = $track->getRawKey();
        if (null === $rawKey || !$this->storage->exists(StorageArea::Private, $rawKey)) {
            $this->fail($trackId, FailureReason::ProcessingError, 'quarantine object missing', $context);

            return;
        }
        if ($this->storage->size(StorageArea::Private, $rawKey) > TrackAudioProcessor::MAX_MP4_SIZE_BYTES) {
            $this->fail($trackId, FailureReason::TooLarge, 'quarantine object too large', $context);

            return;
        }

        $audioKey = MediaKeys::trackAudio($trackId->toRfc4122());
        $temp = new TempFiles();
        try {
            $source = $temp->adopt($this->storage->downloadToTemp(StorageArea::Private, $rawKey));
            $target = $temp->create('.m4a');

            try {
                $durationMs = $this->audio->process($source, $target);
            } catch (MediaRejected $e) {
                $this->fail($trackId, $e->reason, $e->getMessage(), $context);

                return; // temp files cleaned by the finally below
            }

            $stream = fopen($target, 'r');
            if (false === $stream) {
                throw new \RuntimeException('cannot open transcoded file');
            }
            try {
                $this->storage->write(StorageArea::Private, $audioKey, $stream, 'audio/mp4');
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }
        } finally {
            $temp->cleanup();
        }

        $marked = $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($trackId, $audioKey, $durationMs): bool {
            $track = LockedFind::find($em, Track::class, $trackId);
            if (null === $track || MediaStatus::Processing !== $track->getStatus()) {
                return false;
            }
            $track->markReady($audioKey, $durationMs);

            return true;
        });

        if (!$marked) {
            // Deleted (or settled by a concurrent delivery) while we were transcoding.
            if (null === $this->em->find(Track::class, $trackId)) {
                $this->storage->delete(StorageArea::Private, $audioKey);
            }
            $this->logger->info('ProcessTrack: track changed during processing, result discarded', $context);

            return;
        }

        // The DB no longer references the quarantine object (markReady nulls raw_key).
        // A failure here only leaves an orphan for CleanupQuarantine.
        try {
            $this->storage->delete(StorageArea::Private, $rawKey);
        } catch (\Throwable $e) {
            $this->logger->warning('ProcessTrack: could not delete quarantine object', $context + ['exception' => $e]);
        }

        $this->logger->info('ProcessTrack: ready', $context + ['durationMs' => $durationMs]);
    }

    /**
     * Final rejection = business outcome (FB-10): mark the track failed and
     * let the handler return, so the message is acknowledged normally (no
     * retry, nothing in the `failed` transport, no CRITICAL log). Only
     * infrastructure errors (storage, DB) propagate and get retried.
     *
     * @param array<string, mixed> $context
     */
    private function fail(Uuid $trackId, FailureReason $reason, string $detail, array $context): void
    {
        $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($trackId, $reason): void {
            $track = LockedFind::find($em, Track::class, $trackId);
            if (null !== $track && MediaStatus::Processing === $track->getStatus()) {
                $track->markFailed($reason);
            }
        });
        $this->logger->notice('ProcessTrack: rejected', $context + ['reason' => $reason->value, 'detail' => $detail]);
    }
}
