<?php

declare(strict_types=1);

namespace App\Media;

use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lists `quarantine/` in the private bucket (same Flysystem storage as
 * FlysystemObjectStorage). Streams the listing: no full key list in memory.
 */
final readonly class FlysystemQuarantineScanner implements QuarantineScanner
{
    public function __construct(
        #[Autowire(service: 'wave.storage.private')]
        private FilesystemOperator $private,
    ) {
    }

    public function keysModifiedBefore(\DateTimeImmutable $before): iterable
    {
        $limit = $before->getTimestamp();
        $listing = $this->private->listContents(rtrim(MediaKeys::QUARANTINE_PREFIX, '/'), true)
            ->filter(static fn (StorageAttributes $item): bool => $item instanceof FileAttributes);

        foreach ($listing as $item) {
            \assert($item instanceof FileAttributes);
            $modified = $item->lastModified() ?? $this->private->lastModified($item->path());
            if ($modified < $limit) {
                yield $item->path();
            }
        }
    }
}
