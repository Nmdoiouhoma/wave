<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `AddPlaylistItemRequest`. */
final class AddPlaylistItemInput extends InputDto
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public ?string $trackId = null;
}
