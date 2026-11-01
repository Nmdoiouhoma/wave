<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlaylistView;
use App\Mapper\PlaylistMapper;
use App\Security\CurrentUser;

/** @implements ProviderInterface<PlaylistView> */
final readonly class PlaylistProvider implements ProviderInterface
{
    public function __construct(private PlaylistLookup $lookup, private CurrentUser $currentUser, private PlaylistMapper $mapper)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlaylistView
    {
        return $this->mapper->toView($this->lookup->visible($uriVariables['playlistId'] ?? null), $this->currentUser->get());
    }
}
