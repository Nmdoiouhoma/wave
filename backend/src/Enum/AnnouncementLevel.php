<?php

declare(strict_types=1);

namespace App\Enum;

/** DATA_MODEL §17. */
enum AnnouncementLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
}
