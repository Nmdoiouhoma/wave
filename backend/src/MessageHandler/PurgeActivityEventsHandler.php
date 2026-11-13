<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeActivityEvents;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Daily retention (DATA_MODEL §15, §16): ordinary events after 90 days, `admin.*` events after
 * 365 days, acknowledged alerts 180 days after their acknowledgement. Batches of 5000 rows, each
 * its own statement, so a backlog never holds one long lock.
 */
#[AsMessageHandler]
final readonly class PurgeActivityEventsHandler
{
    public const int BATCH = 5000;

    public function __construct(private Connection $db, private ClockInterface $clock)
    {
    }

    public function __invoke(PurgeActivityEvents $message): void
    {
        $now = $this->clock->now();
        $cut = static fn (string $period): string => $now->sub(new \DateInterval($period))->format('Y-m-d H:i:s.uP');

        $this->purge("DELETE FROM activity_event WHERE id IN (SELECT id FROM activity_event WHERE category <> 'admin' AND created_at < :before LIMIT ".self::BATCH.')', $cut('P90D'));
        $this->purge("DELETE FROM activity_event WHERE id IN (SELECT id FROM activity_event WHERE category = 'admin' AND created_at < :before LIMIT ".self::BATCH.')', $cut('P365D'));
        $this->purge("DELETE FROM security_alert WHERE id IN (SELECT id FROM security_alert WHERE status = 'acknowledged' AND acknowledged_at < :before LIMIT ".self::BATCH.')', $cut('P180D'));
    }

    private function purge(string $sql, string $before): void
    {
        do {
            $deleted = (int) $this->db->executeStatement($sql, ['before' => $before]);
        } while ($deleted >= self::BATCH);
    }
}
