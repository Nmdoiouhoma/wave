<?php

declare(strict_types=1);

namespace App\Enum;

enum Moderation: string
{
    case Visible = 'visible';
    case Hidden = 'hidden';
}
