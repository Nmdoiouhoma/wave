<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class CurrentUser
{
    public function __construct(private Security $security)
    {
    }

    public function get(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }

    public function require(): User
    {
        return $this->get() ?? throw new ApiProblemException(ProblemCode::Unauthenticated);
    }
}
