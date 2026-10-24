<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Enum\Visibility;
use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** Text fields of `TrackUpload` (multipart). */
final class UploadTrackInput extends InputDto
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(min: 1, max: 120, normalizer: 'trim')]
    public ?string $title = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 32)]
    public ?string $genreId = null;

    #[Assert\Uuid]
    public ?string $artistId = null;

    public Visibility $visibility = Visibility::Public;

    public bool $allowClips = true;
}
