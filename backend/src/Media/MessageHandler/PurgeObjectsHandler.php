<?php

declare(strict_types=1);

namespace App\Media\MessageHandler;

use App\Message\PurgeObjects;
use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Deletes objects after the DB stopped referencing them. Idempotent:
 * ObjectStorage::delete() ignores missing keys, so a redelivery (or a retry
 * after a partial failure) is harmless.
 */
#[AsMessageHandler]
final class PurgeObjectsHandler
{
    public function __construct(
        private readonly ObjectStorage $storage,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(PurgeObjects $message): void
    {
        $area = StorageArea::from($message->area);

        foreach ($message->keys as $key) {
            try {
                $this->storage->delete($area, $key);
            } catch (\InvalidArgumentException $e) {
                // Malformed key (ObjectStorage refuses traversal etc.): retrying cannot help.
                $this->logger->error('PurgeObjects: invalid key skipped', ['area' => $area->value, 'key' => $key, 'exception' => $e]);
            }
        }

        $this->logger->info('PurgeObjects: done', ['area' => $area->value, 'count' => \count($message->keys)]);
    }
}
