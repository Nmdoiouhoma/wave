<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\UserProfileView;
use App\Mapper\UserMapper;
use App\Security\CurrentUser;

/** @implements ProviderInterface<UserProfileView> */
final readonly class UserProfileProvider implements ProviderInterface
{
    public function __construct(private UserLookup $users, private CurrentUser $currentUser, private UserMapper $mapper)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserProfileView
    {
        $user = $this->users->byUsername($uriVariables['username'] ?? null);

        return $this->mapper->toProfile($user, $this->currentUser->get());
    }
}
