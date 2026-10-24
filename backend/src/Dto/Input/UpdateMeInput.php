<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `UpdateMeRequest` (merge-patch; username/email are immutable → unknown property → 422). */
final class UpdateMeInput extends InputDto
{
    #[Assert\Length(min: 1, max: 50, normalizer: 'trim')]
    #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
    public ?string $displayName = null;

    #[Assert\Length(max: 300)]
    public ?string $bio = null;
}
