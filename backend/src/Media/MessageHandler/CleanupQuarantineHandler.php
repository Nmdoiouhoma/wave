<?php

declare(strict_types=1);

namespace App\Media\MessageHandler;

use App\Media\MediaKeys;
use App\Media\QuarantineScanner;
use App\Message\CleanupQuarantine;
use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Daily sweep of the quarantine area (DATA_MODEL §6b, §14):
 *  1. presigned uploads still `pending` after 2 h: mark `expired`, delete the
 *     object; upload rows older than 7 days (not pending): delete;
 *  2. failed tracks/posts older than 7 days: delete the raw object, null the column;
 *  3. orphans (no row references them) older than 24 h: delete.
 * Idempotent: deleting a missing key is not an error, a second run finds nothing.
 */
#[AsMessageHandler]
final class CleanupQuarantineHandler
{
    public const FAILED_RETENTION = '7 days';
    public const ORPHAN_RETENTION = '24 hours';
    public const UPLOAD_PENDING_TTL = '2 hours';
    public const UPLOAD_ROW_RETENTION = '7 days';
    private const BATCH = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly ObjectStorage $storage,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ?QuarantineScanner $scanner = null,
    ) {
    }

    public function __invoke(CleanupQuarantine $message): void
    {
        $now = $this->clock->now();
        $expired = $this->expireUploads($now->modify('-'.self::UPLOAD_PENDING_TTL), $now->modify('-'.self::UPLOAD_ROW_RETENTION));
        $failed = $this->sweepFailed($now->modify('-'.self::FAILED_RETENTION));
        $orphans = $this->sweepOrphans($now->modify('-'.self::ORPHAN_RETENTION));

        $this->logger->info('CleanupQuarantine: done', ['expiredUploads' => $expired, 'failed' => $failed, 'orphans' => $orphans]);
    }

    /**
     * ADR 009: a slot not consumed within 2 h is dead; its object (if the
     * browser uploaded it) is deleted. Expiring is guarded by status so a
     * concurrent consume wins and keeps its object.
     */
    private function expireUploads(\DateTimeImmutable $pendingBefore, \DateTimeImmutable $rowsBefore): int
    {
        if (!$this->tableExists('track_upload')) {
            return 0;
        }
        $count = 0;
        do {
            /** @var list<array{id: string, k: string}> $rows */
            $rows = $this->db->fetchAllAssociative(
                \sprintf("SELECT id, quarantine_key AS k FROM track_upload WHERE status = 'pending' AND created_at < :before ORDER BY id LIMIT %d", self::BATCH),
                ['before' => $pendingBefore->format('Y-m-d H:i:sP')],
            );
            foreach ($rows as $row) {
                $expired = $this->db->executeStatement(
                    "UPDATE track_upload SET status = 'expired' WHERE id = :id AND status = 'pending'",
                    ['id' => $row['id']],
                );
                if (1 === $expired && str_starts_with($row['k'], MediaKeys::QUARANTINE_PREFIX)) {
                    $this->storage->delete(StorageArea::Private, $row['k']);
                }
                ++$count;
            }
        } while (self::BATCH === \count($rows));

        $this->db->executeStatement(
            "DELETE FROM track_upload WHERE status <> 'pending' AND created_at < :before",
            ['before' => $rowsBefore->format('Y-m-d H:i:sP')],
        );

        return $count;
    }

    private function sweepFailed(\DateTimeImmutable $before): int
    {
        $sources = [
            // table => [key column, age column]
            'track' => ['raw_key', 'updated_at'],
            'post' => ['visual_raw_key', 'created_at'], // phase 3; skipped while the table does not exist
        ];
        $count = 0;
        foreach ($sources as $table => [$keyColumn, $ageColumn]) {
            if (!$this->tableExists($table)) {
                continue;
            }
            do {
                /** @var list<array{id: string, k: string}> $rows */
                $rows = $this->db->fetchAllAssociative(
                    \sprintf("SELECT id, %1\$s AS k FROM %2\$s WHERE status = 'failed' AND %1\$s IS NOT NULL AND %3\$s < :before ORDER BY id LIMIT %4\$d", $keyColumn, $table, $ageColumn, self::BATCH),
                    ['before' => $before->format('Y-m-d H:i:sP')],
                );
                foreach ($rows as $row) {
                    if (str_starts_with($row['k'], MediaKeys::QUARANTINE_PREFIX)) {
                        $this->storage->delete(StorageArea::Private, $row['k']);
                    }
                    $this->db->executeStatement(
                        \sprintf('UPDATE %1$s SET %2$s = NULL WHERE id = :id AND %2$s = :k', $table, $keyColumn),
                        ['id' => $row['id'], 'k' => $row['k']],
                    );
                    ++$count;
                }
            } while (self::BATCH === \count($rows));
        }

        return $count;
    }

    private function sweepOrphans(\DateTimeImmutable $before): int
    {
        if (null === $this->scanner) {
            $this->logger->warning('CleanupQuarantine: no QuarantineScanner configured, orphan sweep skipped');

            return 0;
        }

        $count = 0;
        $batch = [];
        foreach ($this->scanner->keysModifiedBefore($before) as $key) {
            if (!str_starts_with($key, MediaKeys::QUARANTINE_PREFIX)) {
                continue;
            }
            $batch[] = $key;
            if (\count($batch) >= self::BATCH) {
                $count += $this->deleteUnreferenced($batch);
                $batch = [];
            }
        }
        if ([] !== $batch) {
            $count += $this->deleteUnreferenced($batch);
        }

        return $count;
    }

    /**
     * @param list<string> $keys
     */
    private function deleteUnreferenced(array $keys): int
    {
        $referenced = [];
        // Pending uploads are younger than 2 h after expireUploads(), but keep
        // the reference check anyway (clock skew, delayed sweep).
        foreach (['track' => 'raw_key', 'post' => 'visual_raw_key', 'track_upload' => 'quarantine_key'] as $table => $column) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $found = $this->db->fetchFirstColumn(
                \sprintf('SELECT %1$s FROM %2$s WHERE %1$s = ANY(:keys)', $column, $table),
                ['keys' => '{'.implode(',', array_map(static fn (string $k): string => '"'.addcslashes($k, '"\\').'"', $keys)).'}'],
            );
            foreach ($found as $k) {
                $referenced[(string) $k] = true;
            }
        }

        $deleted = 0;
        foreach ($keys as $key) {
            if (!isset($referenced[$key])) {
                $this->storage->delete(StorageArea::Private, $key);
                ++$deleted;
            }
        }

        return $deleted;
    }

    private function tableExists(string $table): bool
    {
        return null !== $this->db->fetchOne('SELECT to_regclass(:t)', ['t' => 'public.'.$table]);
    }
}
