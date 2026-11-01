<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RecomputeAffinity;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Stub until W-40 (phase 4) implements ADR 005: the message is already dispatched by likes
 * and counted listens so that the worker consumes it instead of failing it.
 */
#[AsMessageHandler]
final readonly class RecomputeAffinityHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(RecomputeAffinity $message): void
    {
        $this->logger->debug('RecomputeAffinity: not implemented before W-40, skipped', ['userId' => $message->userId]);
    }
}
