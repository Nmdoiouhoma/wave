<?php

declare(strict_types=1);

namespace App\Enum;

enum AlertStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
}
