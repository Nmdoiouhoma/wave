<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\PurgeAccountTokens;
use App\Repository\AccountTokenRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** Daily: account_token rows older than 7 days (DATA_MODEL §3b). */
#[AsMessageHandler]
final readonly class PurgeAccountTokensHandler
{
    public function __construct(private AccountTokenRepository $tokens, private ClockInterface $clock)
    {
    }

    public function __invoke(PurgeAccountTokens $message): void
    {
        $this->tokens->purgeOlderThan($this->clock->now()->sub(new \DateInterval('P7D')));
    }
}
