<?php

declare(strict_types=1);

namespace App\Upload;

use App\Message\ProcessImage;
use App\Message\PurgeObjects;
use App\Storage\StorageArea;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Avatar/cover uploads (202): validate, store in quarantine, dispatch ProcessImage.
 * The media handler sets the new key and purges the previous one.
 */
final readonly class ImageUploader
{
    public function __construct(
        private QuarantineWriter $quarantine,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @param 'user_avatar'|'artist_avatar'|'track_cover' $target
     */
    public function accept(Request $request, string $target, Uuid $targetId): void
    {
        $file = UploadedFileValidator::require($request, 'file', UploadedFileValidator::IMAGE_MAX_BYTES, UploadedFileValidator::IMAGE_TYPES);
        $key = self::quarantineKey($target, $targetId->toRfc4122(), Uuid::v4()->toRfc4122());
        $this->quarantine->write($file, $key);
        $this->bus->dispatch(new ProcessImage($target, $targetId->toRfc4122(), $key));
    }

    /** Removing an image: the DB stops pointing at it first, the object goes asynchronously. */
    public function purge(?string $publicKey): void
    {
        if (null !== $publicKey) {
            $this->bus->dispatch(new PurgeObjects(StorageArea::Public->value, [$publicKey]));
        }
    }

    public static function quarantineKey(string $target, string $targetId, string $uuid): string
    {
        return match ($target) {
            ProcessImage::TARGET_USER_AVATAR => "quarantine/avatars/users/{$targetId}/{$uuid}",
            ProcessImage::TARGET_ARTIST_AVATAR => "quarantine/avatars/artists/{$targetId}/{$uuid}",
            ProcessImage::TARGET_TRACK_COVER => "quarantine/covers/{$targetId}/{$uuid}",
            default => throw new \InvalidArgumentException('Unknown image target '.$target),
        };
    }
}
