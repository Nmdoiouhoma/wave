<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `LoginRequest`. */
final class LoginInput extends InputDto
{
    #[Assert\NotNull]
    public ?string $email = null;

    #[Assert\NotNull]
    public ?string $password = null;
}
