<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `ModerationDecisionRequest` (DATA_MODEL §13). */
final class ModerationDecisionInput extends InputDto
{
    public const string HIDE = 'hide';
    public const string DISMISS = 'dismiss';
    public const string SUSPEND = 'suspend';

    #[Assert\NotNull]
    #[Assert\Choice(choices: [self::HIDE, self::DISMISS, self::SUSPEND])]
    public ?string $action = null;

    #[Assert\Length(max: 500)]
    public ?string $note = null;
}
