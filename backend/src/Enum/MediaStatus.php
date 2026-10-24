<?php

declare(strict_types=1);

namespace App\Enum;

enum MediaStatus: string
{
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';
}
