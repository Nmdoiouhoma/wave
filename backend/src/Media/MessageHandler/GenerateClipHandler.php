<?php

declare(strict_types=1);

namespace App\Media\MessageHandler;

use App\Entity\Post;
use App\Enum\FailureReason;
use App\Enum\MediaStatus;
use App\Media\ClipProcessor;
use App\Media\Exception\MediaRejected;
use App\Media\LockedFind;
use App\Media\MediaKeys;
use App\Media\PostMediaFailure;
use App\Media\TempFiles;
use App\Message\GenerateClip;
use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Processed track -> private `audio/clips/{postId}.m4a` (W-31). Produced for every post whatever the
 * track's audience: access is decided at read time (FB-18), never here.
 *
 * Order-safe with ProcessPostVisual: the key is recorded under the post's row lock, and the post turns
 * `ready` in that same transaction when the visual is already there (or absent). Idempotent: a post
 * that is gone or no longer `processing` is a no-op; a post whose clip is already recorded is only
 * re-checked for readiness (a delivery may have died between the two steps). A key written for a post
 * deleted (or failed) meanwhile is purged here, since nothing references it.
 */
#[AsMessageHandler]
final class GenerateClipHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ObjectStorage $storage,
        private readonly ClipProcessor $clips,
        private readonly PostMediaFailure $failure,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(GenerateClip $message): void
    {
        if (!Uuid::isValid($message->postId)) {
            $this->logger->error('GenerateClip: invalid post id, dropped', ['postId' => $message->postId]);

            return;
        }
        $postId = Uuid::fromString($message->postId);
        $context = ['postId' => $postId->toRfc4122()];

        $post = $this->em->find(Post::class, $postId);
        if (null === $post || MediaStatus::Processing !== $post->getStatus()) {
            $this->logger->info('GenerateClip: post gone or not processing, skipped', $context);

            return;
        }
        if (null !== $post->getClipKey()) {
            $this->recheckReady($postId);
            $this->logger->info('GenerateClip: clip already recorded, skipped', $context);

            return;
        }

        $audioKey = $post->getTrack()->getAudioKey();
        if (null === $audioKey || !$this->storage->exists(StorageArea::Private, $audioKey)) {
            $this->failure->mark($postId, FailureReason::ProcessingError);
            $this->logger->notice('GenerateClip: processed track audio missing', $context);

            return;
        }

        $clipKey = MediaKeys::postClip($postId->toRfc4122());
        $temp = new TempFiles();
        try {
            $source = $temp->adopt($this->storage->downloadToTemp(StorageArea::Private, $audioKey));
            $target = $temp->create('.m4a');
            try {
                $this->clips->cut($source, $target, $post->getClipStartMs(), $post->getClipDurationMs());
            } catch (MediaRejected $e) {
                $this->failure->mark($postId, $e->reason);
                $this->logger->notice('GenerateClip: rejected', $context + ['reason' => $e->reason->value, 'detail' => $e->getMessage()]);

                return;
            }
            $stream = fopen($target, 'r');
            if (false === $stream) {
                throw new \RuntimeException('cannot open clip file');
            }
            try {
                $this->storage->write(StorageArea::Private, $clipKey, $stream, 'audio/mp4');
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }
        } finally {
            $temp->cleanup();
        }

        $outcome = $this->em->wrapInTransaction(static function (EntityManagerInterface $em) use ($postId, $clipKey): string {
            $post = LockedFind::find($em, Post::class, $postId);
            if (null === $post) {
                return 'gone';
            }
            if (MediaStatus::Processing !== $post->getStatus()) {
                // Failed meanwhile (the visual was rejected) or settled by a concurrent delivery.
                return $post->getClipKey() === $clipKey ? 'recorded' : 'settled';
            }
            $post->setClipKey($clipKey);
            $post->markReadyIfComplete();

            return 'recorded';
        });

        if ('recorded' !== $outcome) {
            // Nothing references the key we just wrote: purge it (revue qa vague 1, bas 3).
            $this->storage->delete(StorageArea::Private, $clipKey);
            $this->logger->info('GenerateClip: post gone or settled during processing, clip purged', $context + ['outcome' => $outcome]);

            return;
        }
        $this->logger->info('GenerateClip: clip recorded', $context);
    }

    private function recheckReady(Uuid $postId): void
    {
        $this->em->wrapInTransaction(static function (EntityManagerInterface $em) use ($postId): void {
            LockedFind::find($em, Post::class, $postId)?->markReadyIfComplete();
        });
    }
}
