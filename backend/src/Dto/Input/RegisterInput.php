<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Entity\User;
use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `RegisterRequest`. */
final class RegisterInput extends InputDto
{
    #[Assert\NotBlank]
    #[Assert\Email(mode: Assert\Email::VALIDATION_MODE_STRICT, normalizer: 'trim')]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 10, max: 128)]
    public ?string $password = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9_.]{3,30}$/', message: 'Use 3 to 30 lowercase letters, digits, "_" or ".".')]
    #[Assert\Choice(choices: User::RESERVED_USERNAMES, match: false, message: 'This username is reserved.')]
    public ?string $username = null;

    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(min: 1, max: 50, normalizer: 'trim')]
    public ?string $displayName = null;
}
