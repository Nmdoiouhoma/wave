<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `AccountTokenRequest`. */
final class AccountTokenInput extends InputDto
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 128)]
    public ?string $token = null;
}
