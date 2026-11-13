<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Enum\AnnouncementLevel;
use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `UpdateAnnouncementRequest` (merge-patch, at least one field; only `endsAt` may be null). */
final class UpdateAnnouncementInput extends InputDto
{
    #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
    #[Assert\Length(max: 80, normalizer: 'trim')]
    #[Assert\Regex(pattern: CreateAnnouncementInput::CLEAN_LINE, message: CreateAnnouncementInput::UNCLEAN)]
    public ?string $title = null;

    #[Assert\NotBlank(allowNull: true, normalizer: 'trim')]
    #[Assert\Length(max: 500, normalizer: 'trim')]
    #[Assert\Regex(pattern: CreateAnnouncementInput::CLEAN_TEXT, message: CreateAnnouncementInput::UNCLEAN)]
    public ?string $body = null;

    public ?AnnouncementLevel $level = null;

    public ?string $startsAt = null;

    public ?string $endsAt = null;
}
