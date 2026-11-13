<?php

declare(strict_types=1);

namespace App\Command\Admin;

enum AdminRoleOutcome
{
    case Changed;
    case Unchanged;
    case NotFound;
    case LastAdmin;
}
