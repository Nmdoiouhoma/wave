<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `CreateArtistRequest` / `UpdateArtistRequest` (name required on create only). */
final class CreateArtistInput extends InputDto
{
    #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
    #[Assert\Length(min: 1, max: 80, normalizer: 'trim')]
    public ?string $name = null;

    #[Assert\Length(max: 1000)]
    public ?string $bio = null;
}
