<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

final class AccountSuspendedException extends CustomUserMessageAccountStatusException
{
    public function __construct()
    {
        parent::__construct('Account suspended.');
    }
}
