<?php

declare(strict_types=1);

namespace App\Enum;

enum VisualType: string
{
    case Image = 'image';
    case Video = 'video';
}
