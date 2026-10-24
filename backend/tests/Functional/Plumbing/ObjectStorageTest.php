<?php

declare(strict_types=1);

namespace App\Tests\Functional\Plumbing;

use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use App\Tests\Functional\WaveApiTestCase;

/** W-07: ObjectStorage behind the in-memory Flysystem adapters of the test env. */
final class ObjectStorageTest extends WaveApiTestCase
{
    public function testAreasAreSeparateBuckets(): void
    {
        $storage = $this->storage();
        $storage->write(StorageArea::Private, 'audio/tracks/a.m4a', $this->stream('private bytes'), 'audio/mp4');

        self::assertTrue($storage->exists(StorageArea::Private, 'audio/tracks/a.m4a'));
        self::assertFalse($storage->exists(StorageArea::Public, 'audio/tracks/a.m4a'));
        self::assertSame(13, $storage->size(StorageArea::Private, 'audio/tracks/a.m4a'));
        self::assertSame('private bytes', stream_get_contents($storage->readStream(StorageArea::Private, 'audio/tracks/a.m4a')));
    }

    public function testDownloadToTempAndIdempotentDelete(): void
    {
        $storage = $this->storage();
        $storage->write(StorageArea::Private, 'quarantine/tracks/x/source', $this->stream('raw'), 'audio/mpeg');

        $path = $storage->downloadToTemp(StorageArea::Private, 'quarantine/tracks/x/source');
        try {
            self::assertSame('raw', file_get_contents($path));
        } finally {
            unlink($path);
        }

        $storage->delete(StorageArea::Private, 'quarantine/tracks/x/source');
        $storage->delete(StorageArea::Private, 'quarantine/tracks/x/source');
        self::assertFalse($storage->exists(StorageArea::Private, 'quarantine/tracks/x/source'));
    }

    public function testUrlsAndRedirectPaths(): void
    {
        $storage = $this->storage();

        self::assertSame('http://wave.test/media/images/covers/t/u.webp', $storage->publicUrl('images/covers/t/u.webp'));
        self::assertSame('/_protected/private/audio/tracks/t.m4a', $storage->internalRedirectPath('audio/tracks/t.m4a'));
    }

    public function testTraversalKeysAreRejected(): void
    {
        foreach (['../etc/passwd', '/abs', 'a/../b', 'a//b/..', 'sp ace'] as $key) {
            try {
                $this->storage()->publicUrl($key);
                self::fail('accepted '.$key);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function storage(): ObjectStorage
    {
        return static::getContainer()->get(ObjectStorage::class);
    }

    /** @return resource */
    private function stream(string $content)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }
}
