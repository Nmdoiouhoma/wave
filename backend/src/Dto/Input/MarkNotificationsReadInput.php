<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `MarkNotificationsReadRequest`: `upTo` is an RFC 3339 date-time (`DateTime` schema), no other key. */
final class MarkNotificationsReadInput extends InputDto
{
    public const string PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    #[Assert\NotNull]
    #[Assert\Regex(pattern: self::PATTERN, message: 'upTo must be an RFC 3339 date-time.')]
    public ?string $upTo = null;
}
