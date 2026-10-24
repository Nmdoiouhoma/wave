<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Media\MediaKeys;
use App\Message\ProcessImage;
use PHPUnit\Framework\TestCase;

final class MediaKeysTest extends TestCase
{
    private const USER = '0192f3a4-1111-7aaa-8bbb-000000000001';
    private const V1 = '0192f3a4-2222-7aaa-8bbb-000000000001';
    private const V2 = '0192f3a5-2222-7aaa-8bbb-000000000001';

    public function testTrackAudioKey(): void
    {
        self::assertSame('audio/tracks/'.self::USER.'.m4a', MediaKeys::trackAudio(strtoupper(self::USER)));
    }

    public function testProcessedImageKeyFollowsDataModel(): void
    {
        self::assertSame(
            ['key' => 'images/avatars/users/'.self::USER.'/'.self::V1.'.webp', 'version' => self::V1],
            MediaKeys::processedImage(ProcessImage::TARGET_USER_AVATAR, self::USER, 'quarantine/avatars/users/'.self::USER.'/'.self::V1),
        );
        self::assertSame(
            'images/covers/'.self::USER.'/'.self::V1.'.webp',
            MediaKeys::processedImage(ProcessImage::TARGET_TRACK_COVER, self::USER, 'quarantine/covers/'.self::USER.'/'.self::V1)['key'] ?? null,
        );
        self::assertSame(
            'images/avatars/artists/'.self::USER.'/'.self::V1.'.webp',
            MediaKeys::processedImage(ProcessImage::TARGET_ARTIST_AVATAR, self::USER, 'quarantine/avatars/artists/'.self::USER.'/'.self::V1)['key'] ?? null,
        );
    }

    public function testQuarantineKeyMustBelongToTheTarget(): void
    {
        $other = '0192f3a4-1111-7aaa-8bbb-000000000002';
        self::assertNull(MediaKeys::processedImage(ProcessImage::TARGET_USER_AVATAR, self::USER, 'quarantine/avatars/users/'.$other.'/'.self::V1));
        self::assertNull(MediaKeys::processedImage(ProcessImage::TARGET_USER_AVATAR, self::USER, 'quarantine/covers/'.self::USER.'/'.self::V1));
        self::assertNull(MediaKeys::processedImage(ProcessImage::TARGET_USER_AVATAR, self::USER, 'quarantine/tracks/'.self::USER.'/source'));
        self::assertNull(MediaKeys::processedImage(ProcessImage::TARGET_USER_AVATAR, self::USER, 'audio/tracks/'.self::USER.'.m4a'));
        self::assertNull(MediaKeys::processedImage('nope', self::USER, 'quarantine/avatars/users/'.self::USER.'/'.self::V1));
    }

    public function testVersionOrdering(): void
    {
        self::assertTrue(MediaKeys::isOlderVersion(self::V1, self::V2));
        self::assertFalse(MediaKeys::isOlderVersion(self::V2, self::V1));
        self::assertFalse(MediaKeys::isOlderVersion(self::V1, null));
        self::assertFalse(MediaKeys::isOlderVersion(self::V1, '0192f3a5-2222-4aaa-8bbb-000000000001'), 'non-v7 never stale');
        self::assertSame(self::V1, MediaKeys::imageVersion('images/covers/x/'.self::V1.'.webp'));
        self::assertNull(MediaKeys::imageVersion(null));
    }
}
