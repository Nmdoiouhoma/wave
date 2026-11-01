<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Enum\ListenSource;
use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `ListenRequest`. */
final class ListenInput extends InputDto
{
    #[Assert\NotNull]
    #[Assert\Range(min: 0, max: 3_600_000)]
    public ?int $listenedMs = null;

    #[Assert\NotNull]
    public ?ListenSource $source = null;

    #[Assert\Uuid]
    public ?string $postId = null;
}
