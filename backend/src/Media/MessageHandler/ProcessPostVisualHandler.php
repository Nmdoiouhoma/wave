<?php

declare(strict_types=1);

namespace App\Media\MessageHandler;

use App\Entity\Post;
use App\Enum\FailureReason;
use App\Enum\MediaStatus;
use App\Enum\VisualType;
use App\Media\Exception\MediaRejected;
use App\Media\LockedFind;
use App\Media\MediaKeys;
use App\Media\PostMediaFailure;
use App\Media\PostVisualProcessor;
use App\Media\TempFiles;
use App\Message\ProcessPostVisual;
use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Quarantined `quarantine/posts/{postId}/visual` -> public WebP image, or muted H.264 video + WebP poster
 * (W-31, DATA_MODEL §14). Same contract as GenerateClipHandler: keys recorded under the post's row lock,
 * `ready` set in the same transaction once the clip exists, keys purged when the post vanished or failed
 * meanwhile, rejection = `failed` + reason and a normal acknowledgement. The quarantine object is deleted
 * on success; after a rejection it is kept for CleanupQuarantine (7 days).
 */
#[AsMessageHandler]
final class ProcessPostVisualHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ObjectStorage $storage,
        private readonly PostVisualProcessor $visuals,
        private readonly PostMediaFailure $failure,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    public function __invoke(ProcessPostVisual $message): void
    {
        if (!Uuid::isValid($message->postId)) {
            $this->logger->error('ProcessPostVisual: invalid post id, dropped', ['postId' => $message->postId]);

            return;
        }
        $postId = Uuid::fromString($message->postId);
        $context = ['postId' => $postId->toRfc4122()];

        $post = $this->em->find(Post::class, $postId);
        if (null === $post || MediaStatus::Processing !== $post->getStatus()) {
            $this->logger->info('ProcessPostVisual: post gone or not processing, skipped', $context);

            return;
        }
        $type = $post->getVisualType();
        if (null === $type) {
            $this->logger->warning('ProcessPostVisual: post has no visual, skipped', $context);

            return;
        }
        if (null !== $post->getVisualKey()) {
            $this->em->wrapInTransaction(static function (EntityManagerInterface $em) use ($postId): void {
                LockedFind::find($em, Post::class, $postId)?->markReadyIfComplete();
            });
            $this->logger->info('ProcessPostVisual: visual already recorded, skipped', $context);

            return;
        }

        $rawKey = $post->getVisualRawKey();
        if (null === $rawKey || $rawKey !== 'quarantine/posts/'.$postId->toRfc4122().'/visual' || !$this->storage->exists(StorageArea::Private, $rawKey)) {
            $this->failure->mark($postId, FailureReason::ProcessingError);
            $this->logger->notice('ProcessPostVisual: quarantine object missing', $context);

            return;
        }

        $keys = MediaKeys::postVisual($postId->toRfc4122(), $this->secret);
        /** @var list<string> $written public keys written by this delivery */
        $written = [];
        $visualKey = VisualType::Image === $type ? $keys['image'] : $keys['video'];
        $posterKey = VisualType::Video === $type ? $keys['poster'] : null;

        $temp = new TempFiles();
        try {
            $source = $temp->adopt($this->storage->downloadToTemp(StorageArea::Private, $rawKey));
            try {
                if (VisualType::Image === $type) {
                    $target = $temp->create('.webp');
                    $this->visuals->image($source, $target);
                    $this->put($visualKey, $target, 'image/webp', $written);
                } else {
                    $target = $temp->create('.mp4');
                    $poster = $temp->create('.webp');
                    $this->visuals->video($source, $target, $poster);
                    $this->put($visualKey, $target, 'video/mp4', $written);
                    $this->put((string) $posterKey, $poster, 'image/webp', $written);
                }
            } catch (MediaRejected $e) {
                $this->failure->mark($postId, $e->reason);
                $this->logger->notice('ProcessPostVisual: rejected', $context + ['reason' => $e->reason->value, 'detail' => $e->getMessage()]);

                return; // temp files cleaned by the finally below
            } catch (\Throwable $e) {
                // A partial write must not outlive the failure (the retry rewrites the same keys).
                $this->purge($written);

                throw $e;
            }
        } finally {
            $temp->cleanup();
        }

        $outcome = $this->em->wrapInTransaction(static function (EntityManagerInterface $em) use ($postId, $visualKey, $posterKey): string {
            $post = LockedFind::find($em, Post::class, $postId);
            if (null === $post) {
                return 'gone';
            }
            if (MediaStatus::Processing !== $post->getStatus()) {
                return $post->getVisualKey() === $visualKey ? 'recorded' : 'settled';
            }
            $post->setVisual($visualKey, $posterKey);
            $post->markReadyIfComplete();

            return 'recorded';
        });

        if ('recorded' !== $outcome) {
            $this->purge($written);
            $this->logger->info('ProcessPostVisual: post gone or settled during processing, visual purged', $context + ['outcome' => $outcome]);
            $this->deleteQuarantine($rawKey, $context);

            return;
        }

        $this->deleteQuarantine($rawKey, $context);
        $this->logger->info('ProcessPostVisual: visual recorded', $context + ['type' => $type->value]);
    }

    /**
     * @param list<string> $written
     */
    private function put(string $key, string $file, string $contentType, array &$written): void
    {
        $stream = fopen($file, 'r');
        if (false === $stream) {
            throw new \RuntimeException('cannot open processed visual');
        }
        try {
            $this->storage->write(StorageArea::Public, $key, $stream, $contentType);
            $written[] = $key;
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * @param list<string> $keys
     */
    private function purge(array $keys): void
    {
        foreach ($keys as $key) {
            try {
                $this->storage->delete(StorageArea::Public, $key);
            } catch (\Throwable $e) {
                $this->logger->warning('ProcessPostVisual: could not purge key', ['key' => $key, 'exception' => $e]);
            }
        }
    }

    /**
     * @param array<string, string> $context
     */
    private function deleteQuarantine(string $key, array $context): void
    {
        try {
            $this->storage->delete(StorageArea::Private, $key);
        } catch (\Throwable $e) {
            $this->logger->warning('ProcessPostVisual: could not delete quarantine object', $context + ['exception' => $e]);
        }
    }
}
