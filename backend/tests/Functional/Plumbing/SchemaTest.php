<?php

declare(strict_types=1);

namespace App\Tests\Functional\Plumbing;

use App\Tests\Functional\WaveApiTestCase;
use Doctrine\DBAL\Exception\DriverException;

/** W-04: DB-level rules written by hand in the baseline migration. */
final class SchemaTest extends WaveApiTestCase
{
    public function testSearchVectorsAreMaintainedByTriggers(): void
    {
        $alice = $this->createUser('alice');
        $artist = $this->createArtist($alice, 'Beyoncé', 'beyonce');
        $track = $this->createTrack($artist, 'Crème Brûlée');
        $db = $this->em()->getConnection();

        $match = static fn (string $table, string $q, string $id): bool => (bool) $db->fetchOne(
            "SELECT search_vector @@ to_tsquery('simple', wave_unaccent(lower(:q))) FROM {$table} WHERE id = :id",
            ['q' => $q, 'id' => $id],
        );
        self::assertTrue($match('artist', 'beyonce', $artist->getId()->toRfc4122()));
        self::assertTrue($match('track', 'creme & beyonce', $track->getId()->toRfc4122()));

        $db->executeStatement("UPDATE artist SET name = 'Daft Punk' WHERE id = ?", [$artist->getId()->toRfc4122()]);
        self::assertTrue($match('track', 'daft', $track->getId()->toRfc4122()), 'artist rename refreshes its tracks');
        self::assertFalse($match('track', 'beyonce', $track->getId()->toRfc4122()));
    }

    public function testCheckConstraintsGuardEnumsAndCounters(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $db = $this->em()->getConnection();

        foreach ([
            "UPDATE track SET status = 'weird' WHERE id = :id",
            'UPDATE track SET play_count = -1 WHERE id = :id',
            'UPDATE track SET duration_ms = 600001 WHERE id = :id',
        ] as $sql) {
            $db->executeStatement('SAVEPOINT chk');
            try {
                $db->executeStatement($sql, ['id' => $track->getId()->toRfc4122()]);
                self::fail('constraint not enforced: '.$sql);
            } catch (DriverException) {
                $db->executeStatement('ROLLBACK TO SAVEPOINT chk');
            }
        }
        self::assertSame(45, (int) $db->fetchOne('SELECT count(*) FROM genre'));
    }
}
