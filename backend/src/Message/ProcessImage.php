<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Transport `media`. Handler: media-infra (App\Media). Validate + resize to WebP
 * (DATA_MODEL §14), set the key on the entity, purge the previous key.
 */
final readonly class ProcessImage
{
    public const string TARGET_USER_AVATAR = 'user_avatar';
    public const string TARGET_ARTIST_AVATAR = 'artist_avatar';
    public const string TARGET_TRACK_COVER = 'track_cover';

    /**
     * @param 'user_avatar'|'artist_avatar'|'track_cover' $target
     */
    public function __construct(
        public string $target,
        public string $targetId,
        public string $quarantineKey,
    ) {
    }
}
