<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `PostViewsRequest`: 1–50 items `{postId, watchedMs}`, no other key. */
final class PostViewsInput extends InputDto
{
    public const int MAX_ITEMS = 50;
    public const int MAX_WATCHED_MS = 600_000;

    /** @var list<mixed>|null */
    #[Assert\NotNull]
    #[Assert\Type('list')]
    #[Assert\Count(min: 1, max: self::MAX_ITEMS)]
    #[Assert\All([
        new Assert\Collection(
            fields: [
                'postId' => [new Assert\NotNull(), new Assert\Type('string'), new Assert\Uuid()],
                'watchedMs' => [new Assert\NotNull(), new Assert\Type('int'), new Assert\Range(min: 0, max: self::MAX_WATCHED_MS)],
            ],
            allowExtraFields: false,
            allowMissingFields: false,
        ),
    ])]
    public ?array $views = null;
}
