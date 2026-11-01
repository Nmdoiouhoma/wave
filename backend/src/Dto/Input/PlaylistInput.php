<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `CreatePlaylistRequest` / `UpdatePlaylistRequest` (name required on create only). */
final class PlaylistInput extends InputDto
{
    #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
    #[Assert\Length(min: 1, max: 100, normalizer: 'trim')]
    public ?string $name = null;

    #[Assert\Length(max: 300)]
    public ?string $description = null;

    public ?bool $isPublic = null;
}
