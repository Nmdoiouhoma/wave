<?php

declare(strict_types=1);

namespace App\Search;

use Symfony\Component\Uid\Uuid;

/** CONVENTIONS §7: ids only, ordered by relevance, already filtered by visibility. */
interface SearchProvider
{
    public const int MAX_RESULTS = 200;

    /** Ids ordered by relevance, already filtered by visibility for $viewerId. */
    public function search(string $query, SearchType $type, Uuid $viewerId, int $offset, int $limit): SearchHits;
}
