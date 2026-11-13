<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** Optional `{note}` body of `DELETE /admin/users/{userId}/suspension` (an empty body is accepted). */
final class AdminOptionalNoteInput extends InputDto
{
    #[Assert\Length(max: 500)]
    public ?string $note = null;
}
