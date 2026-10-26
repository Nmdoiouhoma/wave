<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Media\MessageHandler\CleanupQuarantineHandler;
use App\Message\CleanupQuarantine;
use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use Doctrine\DBAL\Connection;

/** W-66 (ADR 009): upload slots expire, their objects are purged, a dead slot cannot be consumed. */
final class UploadLifecycleSecurityTest extends SecurityTestCase
{
    public function testExpiredPendingUploadIsPurgedAndCannotBeConsumed(): void
    {
        $alice = $this->createUser('alice');
        $this->createArtist($alice);
        $uploadId = $this->uploadAudio($alice);
        $conn = static::getContainer()->get(Connection::class);
        $key = (string) $conn->fetchOne('SELECT quarantine_key FROM track_upload WHERE id = :id', ['id' => $uploadId]);
        $conn->executeStatement("UPDATE track_upload SET created_at = clock_timestamp() - interval '3 hours' WHERE id = :id", ['id' => $uploadId]);
        $this->em()->clear();

        static::getContainer()->get(CleanupQuarantineHandler::class)(new CleanupQuarantine());

        self::assertSame('expired', $conn->fetchOne('SELECT status FROM track_upload WHERE id = :id', ['id' => $uploadId]));
        self::assertFalse(static::getContainer()->get(ObjectStorage::class)->exists(StorageArea::Private, $key), 'object of the dead slot deleted');
        $this->assertProblem($this->api('POST', '/api/v1/tracks', $alice, ['json' => ['uploadId' => $uploadId, 'title' => 'Late', 'genreId' => 'pop']]), 409, 'upload_expired');
    }

    public function testConsumedUploadIsNotTouchedByCleanup(): void
    {
        $alice = $this->createUser('alice');
        $this->createArtist($alice);
        $uploadId = $this->uploadAudio($alice);
        self::assertSame(202, $this->api('POST', '/api/v1/tracks', $alice, ['json' => ['uploadId' => $uploadId, 'title' => 'Ok', 'genreId' => 'pop']])->getStatusCode());
        $conn = static::getContainer()->get(Connection::class);
        // Since F3 the worker reads the server-side copy (track.raw_key), not the upload key.
        $key = (string) $conn->fetchOne('SELECT t.raw_key FROM track_upload u JOIN track t ON t.id = u.track_id WHERE u.id = :id', ['id' => $uploadId]);
        $conn->executeStatement("UPDATE track_upload SET created_at = clock_timestamp() - interval '3 hours' WHERE id = :id", ['id' => $uploadId]);

        static::getContainer()->get(CleanupQuarantineHandler::class)(new CleanupQuarantine());

        self::assertSame('consumed', $conn->fetchOne('SELECT status FROM track_upload WHERE id = :id', ['id' => $uploadId]));
        self::assertTrue(static::getContainer()->get(ObjectStorage::class)->exists(StorageArea::Private, $key), 'the worker still needs it');
    }

    public function testSlotRequestRejectsSpoofedTypesAndSizes(): void
    {
        $alice = $this->createUser('alice');
        foreach ([
            ['x.php', 'audio/mpeg', 100, 415],
            ['x.mp3', 'text/html', 100, 415],
            ['x.mp4.php', 'video/mp4', 100, 415],
            ['x.mp3', 'audio/mpeg', 31_457_281, 413],
            ['x.mp4', 'video/mp4', 209_715_201, 413],
            ['x.mp3', 'audio/mpeg', 0, 422],
            ['x.mp3', 'audio/mpeg', -1, 422],
        ] as [$name, $type, $size, $status]) {
            $r = $this->api('POST', '/api/v1/tracks/uploads', $alice, ['json' => ['filename' => $name, 'size' => $size, 'contentType' => $type]]);
            self::assertSame($status, $r->getStatusCode(), "$name $type $size: ".$r->getContent(false));
        }
        // The client filename never reaches the key.
        $r = $this->api('POST', '/api/v1/tracks/uploads', $alice, ['json' => ['filename' => '../../audio/tracks/x.mp3', 'size' => 10, 'contentType' => 'audio/mpeg']]);
        self::assertSame(201, $r->getStatusCode());
        self::assertMatchesRegularExpression('#^quarantine/uploads/[0-9a-f-]{36}/[0-9a-f-]{36}$#', $this->json($r)['fields']['key']);
    }
}
