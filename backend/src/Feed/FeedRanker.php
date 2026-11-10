<?php

declare(strict_types=1);

namespace App\Feed;

use Symfony\Component\Uid\Uuid;

/** CONVENTIONS §7. Bound by DI alias (ADR 011: SimpleFeedRanker in phase 3, SqlFeedRanker in W-40). */
interface FeedRanker
{
    /**
     * Post ids in display order; all ids must be playable for $viewerId at call time.
     *
     * @throws \App\Problem\ApiProblemException invalid_cursor
     */
    public function forYou(Uuid $viewerId, ?string $cursor, int $limit): RankedPage;
}
