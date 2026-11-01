<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `ReorderPlaylistRequest`: the complete ordered list (set mismatch → 409, checked by the processor). */
final class ReorderPlaylistInput extends InputDto
{
    /** @var list<mixed>|null */
    #[Assert\NotNull]
    #[Assert\Count(max: 500)]
    #[Assert\All([new Assert\Type('string'), new Assert\Uuid()])]
    public ?array $trackIds = null;
}
