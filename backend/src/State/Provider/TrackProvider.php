<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\TrackView;
use App\Mapper\TrackMapper;
use App\Security\CurrentUser;

/** @implements ProviderInterface<TrackView> */
final readonly class TrackProvider implements ProviderInterface
{
    public function __construct(private TrackLookup $lookup, private CurrentUser $currentUser, private TrackMapper $mapper)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TrackView
    {
        $viewer = $this->currentUser->get();

        return $this->mapper->toView($this->lookup->visible($uriVariables['trackId'] ?? null), $viewer);
    }
}
