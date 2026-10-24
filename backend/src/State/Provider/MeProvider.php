<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\MeView;
use App\Mapper\UserMapper;
use App\Security\CurrentUser;

/** @implements ProviderInterface<MeView> */
final readonly class MeProvider implements ProviderInterface
{
    public function __construct(private CurrentUser $currentUser, private UserMapper $mapper)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): MeView
    {
        return $this->mapper->toMe($this->currentUser->require());
    }
}
