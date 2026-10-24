<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;

/** `RefreshRequest` (mobile only; web reads the cookie). */
final class RefreshInput extends InputDto
{
    public ?string $refreshToken = null;
}
