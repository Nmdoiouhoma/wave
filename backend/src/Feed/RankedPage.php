<?php

declare(strict_types=1);

namespace App\Feed;

use Symfony\Component\Uid\Uuid;

final readonly class RankedPage
{
    /** @param list<Uuid> $postIds */
    public function __construct(public array $postIds, public ?string $nextCursor)
    {
    }
}
