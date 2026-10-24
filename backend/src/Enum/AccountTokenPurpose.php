<?php

declare(strict_types=1);

namespace App\Enum;

enum AccountTokenPurpose: string
{
    case EmailVerification = 'email_verification';
    case PasswordReset = 'password_reset';
}
