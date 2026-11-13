<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `AdminNoteRequest` (FB-28): the reason written to the audit journal, required. */
final class AdminNoteInput extends InputDto
{
    #[Assert\NotNull]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 500)]
    public ?string $note = null;
}
