<?php

declare(strict_types=1);

namespace App\Enum;

enum ListenSource: string
{
    case Player = 'player';
    case Feed = 'feed';
}
