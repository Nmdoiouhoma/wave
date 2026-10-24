<?php

declare(strict_types=1);

namespace App\Media;

use App\Message\ProcessImage;

/**
 * Object keys of DATA_MODEL §14 produced by the media handlers.
 */
final class MediaKeys
{
    public const QUARANTINE_PREFIX = 'quarantine/';

    /** ProcessImage target => quarantine/processed sub-directory. */
    private const IMAGE_DIRS = [
        ProcessImage::TARGET_TRACK_COVER => 'covers',
        ProcessImage::TARGET_USER_AVATAR => 'avatars/users',
        ProcessImage::TARGET_ARTIST_AVATAR => 'avatars/artists',
    ];

    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public static function trackAudio(string $trackId): string
    {
        return \sprintf('audio/tracks/%s.m4a', strtolower($trackId));
    }

    /**
     * Public key of the processed image for a quarantine key of the expected
     * shape `quarantine/{dir}/{targetId}/{uuid}`, or null if the key does not
     * belong to that target (never trust a key to point elsewhere).
     *
     * @return array{key: string, version: string}|null
     */
    public static function processedImage(string $target, string $targetId, string $quarantineKey): ?array
    {
        $dir = self::IMAGE_DIRS[$target] ?? null;
        if (null === $dir) {
            return null;
        }
        $id = strtolower($targetId);
        $pattern = \sprintf('#^quarantine/%s/%s/(%s)$#', preg_quote($dir, '#'), preg_quote($id, '#'), self::UUID);
        if (1 !== preg_match($pattern, $quarantineKey, $m)) {
            return null;
        }

        return ['key' => \sprintf('images/%s/%s/%s.webp', $dir, $id, $m[1]), 'version' => $m[1]];
    }

    /**
     * Version uuid of a processed image key (`images/.../{uuid}.webp`), if any.
     */
    public static function imageVersion(?string $key): ?string
    {
        if (null === $key || 1 !== preg_match('#/('.self::UUID.')\.webp$#', $key, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * True when $candidate is an older upload than $current. Both are UUID v7
     * (time-ordered, CONVENTIONS §4), so canonical strings sort by creation time.
     * Unknown versions are never considered stale.
     */
    public static function isOlderVersion(string $candidate, ?string $current): bool
    {
        if (null === $current || !self::isV7($candidate) || !self::isV7($current)) {
            return false;
        }

        return strcmp($candidate, $current) < 0;
    }

    private static function isV7(string $uuid): bool
    {
        return 1 === preg_match('#^[0-9a-f]{8}-[0-9a-f]{4}-7#', $uuid);
    }
}
