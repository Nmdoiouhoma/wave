<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `CreateTrackUploadRequest`. Allowed types/extensions (415) and limits (413) are checked by the processor. */
final class CreateTrackUploadInput extends InputDto
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 255)]
    public ?string $filename = null;

    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $size = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    public ?string $contentType = null;
}
