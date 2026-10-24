<?php

declare(strict_types=1);

namespace App\Enum;

enum ReportReason: string
{
    case Spam = 'spam';
    case Harassment = 'harassment';
    case Hate = 'hate';
    case Sexual = 'sexual';
    case Violence = 'violence';
    case Copyright = 'copyright';
    case Impersonation = 'impersonation';
    case Other = 'other';
}
