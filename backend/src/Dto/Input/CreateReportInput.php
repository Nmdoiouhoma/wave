<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Enum\ReportReason;
use App\Enum\ReportTargetType;
use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `CreateReportRequest` (DATA_MODEL §13). */
final class CreateReportInput extends InputDto
{
    #[Assert\NotNull]
    public ?ReportTargetType $targetType = null;

    #[Assert\NotNull]
    #[Assert\Uuid]
    public ?string $targetId = null;

    #[Assert\NotNull]
    public ?ReportReason $reason = null;

    #[Assert\Length(max: 1000)]
    public ?string $details = null;
}
