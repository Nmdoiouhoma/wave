<?php

declare(strict_types=1);

namespace App\Enum;

enum ReportStatus: string
{
    case Open = 'open';
    case Actioned = 'actioned';
    case Dismissed = 'dismissed';
}
