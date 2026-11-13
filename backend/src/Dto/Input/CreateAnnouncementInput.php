<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Enum\AnnouncementLevel;
use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `CreateAnnouncementRequest` (DATA_MODEL §17). Dates are parsed by AnnouncementProcessor. */
final class CreateAnnouncementInput extends InputDto
{
    /** W-77 F28: no control character, no bidi override or isolate (U+202A-202E, U+2066-2069). */
    public const string CLEAN_LINE = '/^[^\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]*+\z/u';
    /** Same rule, newline (`\n`) allowed: the body may span several lines. */
    public const string CLEAN_TEXT = '/^(?:\n|[^\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}])*+\z/u';
    public const string UNCLEAN = 'This value contains forbidden control or direction characters.';

    #[Assert\NotNull]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 80, normalizer: 'trim')]
    #[Assert\Regex(pattern: self::CLEAN_LINE, message: self::UNCLEAN)]
    public ?string $title = null;

    #[Assert\NotNull]
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: 500, normalizer: 'trim')]
    #[Assert\Regex(pattern: self::CLEAN_TEXT, message: self::UNCLEAN)]
    public ?string $body = null;

    #[Assert\NotNull]
    public ?AnnouncementLevel $level = null;

    public ?string $startsAt = null;

    public ?string $endsAt = null;
}
