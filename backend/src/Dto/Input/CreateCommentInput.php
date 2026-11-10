<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `CreateCommentRequest`: the length rule (1–500 after trim) is checked on the trimmed body by the processor. */
final class CreateCommentInput extends InputDto
{
    #[Assert\NotNull]
    public ?string $body = null;
}
