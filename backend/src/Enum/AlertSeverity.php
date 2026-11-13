<?php

declare(strict_types=1);

namespace App\Enum;

enum AlertSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
