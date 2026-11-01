<?php

declare(strict_types=1);

namespace App\Search;

use Symfony\Component\Uid\Uuid;

final readonly class SearchHits
{
    /**
     * @param list<Uuid> $ids
     * @param int|null   $nextOffset null = no more
     */
    public function __construct(public array $ids, public ?int $nextOffset)
    {
    }
}
