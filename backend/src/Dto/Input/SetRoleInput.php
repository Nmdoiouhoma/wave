<?php

declare(strict_types=1);

namespace App\Dto\Input;

use App\Http\InputDto;
use Symfony\Component\Validator\Constraints as Assert;

/** `SetRoleRequest` (ADR 013 §3): `ROLE_ADMIN` is never accepted through the API. */
final class SetRoleInput extends InputDto
{
    public const string USER = 'ROLE_USER';
    public const string MODERATOR = 'ROLE_MODERATOR';

    #[Assert\NotNull]
    #[Assert\Choice(choices: [self::USER, self::MODERATOR])]
    public ?string $role = null;

    #[Assert\Length(max: 500)]
    public ?string $note = null;
}
