<?php

declare(strict_types=1);

namespace App\Activity;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * Writes the journal rows deferred while a transaction was open (ActivityJournal): after the
 * response is sent, at the end of a console command and after each worker message.
 */
#[AsEventListener(event: KernelEvents::TERMINATE, method: 'flush')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'flush')]
#[AsEventListener(event: WorkerMessageHandledEvent::class, method: 'flush')]
#[AsEventListener(event: WorkerMessageFailedEvent::class, method: 'flush')]
final readonly class ActivityJournalFlusher
{
    public function __construct(private ActivityJournal $journal)
    {
    }

    public function flush(): void
    {
        $this->journal->flush();
    }
}
