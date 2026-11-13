<?php

declare(strict_types=1);

namespace App\Enum;

/** DATA_MODEL §1 / §15. */
enum ActivityCategory: string
{
    case Auth = 'auth';
    case Account = 'account';
    case Content = 'content';
    case Report = 'report';
    case Upload = 'upload';
    case Abuse = 'abuse';
    case Admin = 'admin';
}
