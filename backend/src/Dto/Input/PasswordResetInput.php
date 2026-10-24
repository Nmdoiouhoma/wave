<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `PasswordResetRequest`. */
final class PasswordResetInput extends InputDto
{
    #[Assert\NotBlank]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT, normalizer: 'trim')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;
}
