<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `ChangePasswordRequest`. */
final class ChangePasswordInput extends InputDto
{
    #[Assert\NotNull]
    public ?string $currentPassword = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 10, max: 128)]
    public ?string $newPassword = null;
}
