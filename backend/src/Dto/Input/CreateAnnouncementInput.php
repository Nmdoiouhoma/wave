<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Enum\AnnouncementLevel;
use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `CreateAnnouncementRequest` (DATA_MODEL §17). Dates are parsed by AnnouncementProcessor. */
final class CreateAnnouncementInput extends InputDto
{
    #[Assert\NotNull]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 80, normalizer: 'trim')]
    public ?string $title = null;

    #[Assert\NotNull]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 500, normalizer: 'trim')]
    public ?string $body = null;

    #[Assert\NotNull]
    public ?AnnouncementLevel $level = null;

    public ?string $startsAt = null;

    public ?string $endsAt = null;
}
