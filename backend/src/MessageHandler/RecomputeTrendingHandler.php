<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RecomputeTrending;
use App\Recommendation\TrendingRecomputer;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** ADR 005: recompute `track.trending_score` and `post.trending_score`. */
#[AsMessageHandler]
final readonly class RecomputeTrendingHandler
{
    public function __construct(private TrendingRecomputer $trending)
    {
    }

    public function __invoke(RecomputeTrending $message): void
    {
        $this->trending->recompute();
    }
}
