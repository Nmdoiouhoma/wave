<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\Entity\User;
use App\Problem\ApiProblemException;
use App\Repository\UserRepository;

final readonly class UserLookup
{
    public function __construct(private UserRepository $users)
    {
    }

    /** `{username}` of every `/users/{username}*` route: unknown or suspended → 404 (W-26 F16). */
    public function byUsername(mixed $username): User
    {
        $user = $this->includingSuspended($username);

        return $user->isSuspended() ? throw ApiProblemException::notFound() : $user;
    }

    /** Only for removing one's own link (`DELETE …/follow`, F14/F16): unknown → 404. */
    public function includingSuspended(mixed $username): User
    {
        $user = \is_string($username) && 1 === preg_match('/^[a-z0-9_.]{3,30}$/', $username) ? $this->users->findOneByUsername($username) : null;

        return $user ?? throw ApiProblemException::notFound();
    }
}
