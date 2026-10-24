<?php

declare(strict_types=1);

namespace App\Tests\Functional\Plumbing;

use App\Message\ProcessTrack;
use App\Message\SendVerificationEmail;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * W-07: the Doctrine transport shares the DBAL connection, so a message dispatched
 * inside a transaction is committed or rolled back with the data (CONVENTIONS §2).
 */
final class TransactionalDispatchTest extends WaveApiTestCase
{
    public function testRollbackDiscardsTheMessage(): void
    {
        $em = $this->em();
        $bus = static::getContainer()->get(MessageBusInterface::class);

        $thrown = false;
        try {
            $em->wrapInTransaction(function () use ($bus): void {
                $bus->dispatch(new ProcessTrack('0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e'));
                self::assertCount(1, $this->queued('media', ProcessTrack::class), 'visible inside the transaction');
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            $thrown = 'boom' === $e->getMessage();
        }
        self::assertTrue($thrown, 'the transaction must have thrown');

        self::assertCount(0, $this->queued('media', ProcessTrack::class));
    }

    public function testCommitKeepsTheMessageOnItsTransport(): void
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);
        $this->em()->wrapInTransaction(static function () use ($bus): void {
            $bus->dispatch(new SendVerificationEmail('0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e'));
        });

        self::assertCount(1, $this->queued('async', SendVerificationEmail::class));
        self::assertCount(0, $this->queued('media'));
    }
}
