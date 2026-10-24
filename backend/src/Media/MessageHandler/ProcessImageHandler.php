<?php

declare(strict_types=1);

namespace App\Media\MessageHandler;

use App\Entity\Artist;
use App\Entity\Track;
use App\Entity\User;
use App\Media\Exception\MediaRejected;
use App\Media\ImageProcessor;
use App\Media\ImageVariant;
use App\Media\LockedFind;
use App\Media\MediaKeys;
use App\Media\TempFiles;
use App\Message\ProcessImage;
use App\Message\PurgeObjects;
use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Quarantined avatar/cover -> WebP in the public area (DATA_MODEL §14), then
 * the entity points to the new key and the previous key is purged.
 *
 * Invalid images are dropped silently (logged): the previous key is kept and
 * the quarantine object is left to CleanupQuarantine (orphan after 24 h).
 * Idempotent, and order-safe: an older upload processed after a newer one
 * (versions are UUID v7) never replaces it.
 */
#[AsMessageHandler]
final class ProcessImageHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ObjectStorage $storage,
        private readonly ImageProcessor $images,
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessImage $message): void
    {
        $context = ['target' => $message->target, 'targetId' => $message->targetId, 'key' => $message->quarantineKey];

        $class = match ($message->target) {
            ProcessImage::TARGET_USER_AVATAR => User::class,
            ProcessImage::TARGET_ARTIST_AVATAR => Artist::class,
            ProcessImage::TARGET_TRACK_COVER => Track::class,
        };
        $processed = Uuid::isValid($message->targetId)
            ? MediaKeys::processedImage($message->target, $message->targetId, $message->quarantineKey)
            : null;
        if (null === $processed) {
            $this->logger->error('ProcessImage: malformed message dropped', $context);

            return;
        }
        $targetId = Uuid::fromString($message->targetId);
        $newKey = $processed['key'];
        $variant = ProcessImage::TARGET_TRACK_COVER === $message->target ? ImageVariant::Cover : ImageVariant::Avatar;

        $entity = $this->em->find($class, $targetId);
        if (null === $entity) {
            $this->logger->info('ProcessImage: target gone, skipped', $context);
            $this->deleteQuarantine($message->quarantineKey, $context);

            return;
        }
        $current = self::currentKey($entity);
        if ($current === $newKey || MediaKeys::isOlderVersion($processed['version'], MediaKeys::imageVersion($current))) {
            // Redelivery of an applied message, or superseded by a newer upload.
            $this->logger->info('ProcessImage: already applied or superseded, skipped', $context);
            $this->deleteQuarantine($message->quarantineKey, $context);

            return;
        }
        if (!$this->storage->exists(StorageArea::Private, $message->quarantineKey)) {
            $this->logger->warning('ProcessImage: quarantine object missing, dropped', $context);

            return;
        }

        $temp = new TempFiles();
        try {
            $source = $temp->adopt($this->storage->downloadToTemp(StorageArea::Private, $message->quarantineKey));
            $target = $temp->create('.webp');
            try {
                $this->images->toWebp($source, $target, $variant);
            } catch (MediaRejected $e) {
                $this->logger->notice('ProcessImage: invalid image dropped, previous key kept', $context + ['reason' => $e->reason->value, 'detail' => $e->getMessage()]);

                return;
            }

            $stream = fopen($target, 'r');
            if (false === $stream) {
                throw new \RuntimeException('cannot open converted image');
            }
            try {
                $this->storage->write(StorageArea::Public, $newKey, $stream, 'image/webp');
            } finally {
                if (\is_resource($stream)) {
                    fclose($stream);
                }
            }
        } finally {
            $temp->cleanup();
        }

        // Re-check under lock: a newer upload may have landed meanwhile.
        $previous = $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($class, $targetId, $newKey, $processed): array {
            $entity = LockedFind::find($em, $class, $targetId);
            if (null === $entity) {
                return ['applied' => false, 'old' => null];
            }
            $current = self::currentKey($entity);
            if ($current === $newKey) {
                return ['applied' => true, 'old' => null];
            }
            if (MediaKeys::isOlderVersion($processed['version'], MediaKeys::imageVersion($current))) {
                return ['applied' => false, 'old' => null];
            }
            self::setKey($entity, $newKey);

            return ['applied' => true, 'old' => $current];
        });

        if (!$previous['applied']) {
            $this->storage->delete(StorageArea::Public, $newKey);
            $this->logger->info('ProcessImage: target gone or superseded during processing, result discarded', $context);
        } elseif (null !== $previous['old']) {
            $this->bus->dispatch(new PurgeObjects(StorageArea::Public->value, [$previous['old']]));
        }
        $this->deleteQuarantine($message->quarantineKey, $context);
    }

    private static function currentKey(object $entity): ?string
    {
        return match (true) {
            $entity instanceof Track => $entity->getCoverKey(),
            $entity instanceof User, $entity instanceof Artist => $entity->getAvatarKey(),
            default => throw new \LogicException('unsupported image target'),
        };
    }

    private static function setKey(object $entity, string $key): void
    {
        match (true) {
            $entity instanceof Track => $entity->setCoverKey($key),
            $entity instanceof User, $entity instanceof Artist => $entity->setAvatarKey($key),
            default => throw new \LogicException('unsupported image target'),
        };
    }

    /**
     * @param array<string, string> $context
     */
    private function deleteQuarantine(string $key, array $context): void
    {
        try {
            $this->storage->delete(StorageArea::Private, $key);
        } catch (\Throwable $e) {
            $this->logger->warning('ProcessImage: could not delete quarantine object', $context + ['exception' => $e]);
        }
    }
}
