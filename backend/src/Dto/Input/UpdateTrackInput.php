<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Enum\Visibility;
use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `UpdateTrackRequest` (merge-patch). */
final class UpdateTrackInput extends InputDto
{
    #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
    #[Assert\Length(min: 1, max: 120, normalizer: 'trim')]
    public ?string $title = null;

    #[Assert\Length(max: 32)]
    public ?string $genreId = null;

    #[Assert\Uuid]
    public ?string $artistId = null;

    public ?Visibility $visibility = null;

    public ?bool $allowClips = null;
}
