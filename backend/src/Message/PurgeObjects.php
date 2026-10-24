<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Transport `async`. Handler: media-infra. ObjectStorage::delete() each key (idempotent).
 * Dispatched in batches of at most 500 keys.
 */
final readonly class PurgeObjects
{
    public const int MAX_KEYS = 500;

    /**
     * @param 'private'|'public' $area App\Storage\StorageArea value
     * @param list<string>       $keys
     */
    public function __construct(
        public string $area,
        public array $keys,
    ) {
    }
}
