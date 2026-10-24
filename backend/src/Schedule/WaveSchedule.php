<?php

declare(strict_types=1);

namespace App\Schedule;

use App\Message\CleanupQuarantine;
use App\Message\PurgeAccountTokens;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Recurring jobs (CONVENTIONS §8), all UTC. The scheduler transport `scheduler_default`
 * must have exactly one consumer (worker-async); jobs are redispatched to `async` so
 * they get its retry policy and failure transport.
 *
 * RecomputeTrending (every 15 min) and RecomputeAffinityAll (daily 04:00) are added
 * with their handlers in W-40.
 */
#[AsSchedule]
final readonly class WaveSchedule implements ScheduleProviderInterface
{
    public function __construct(private CacheInterface $cache)
    {
    }

    public function getSchedule(): Schedule
    {
        return new Schedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(
                RecurringMessage::cron('0 3 * * *', new RedispatchMessage(new PurgeAccountTokens(), 'async'), new \DateTimeZone('UTC')),
                RecurringMessage::cron('30 3 * * *', new RedispatchMessage(new CleanupQuarantine(), 'async'), new \DateTimeZone('UTC')),
                // DATA_MODEL §3: expired refresh tokens purged daily.
                RecurringMessage::cron('15 3 * * *', new RedispatchMessage(new RunCommandMessage('gesdinet:jwt:clear'), 'async'), new \DateTimeZone('UTC')),
                // F3: expired rate-limiter rows (cache.rate_limiter on Doctrine DBAL) are never
                // removed on read; prune every pruneable pool daily.
                RecurringMessage::cron('45 3 * * *', new RedispatchMessage(new RunCommandMessage('cache:pool:prune'), 'async'), new \DateTimeZone('UTC')),
            );
    }
}
