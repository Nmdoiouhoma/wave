<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ArtistView;
use App\Artist\ArtistLookup;
use App\Mapper\ArtistMapper;
use App\Security\CurrentUser;

/** @implements ProviderInterface<ArtistView> */
final readonly class ArtistProvider implements ProviderInterface
{
    public function __construct(private ArtistLookup $lookup, private CurrentUser $currentUser, private ArtistMapper $mapper)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ArtistView
    {
        $viewer = $this->currentUser->get();

        return $this->mapper->toView($this->lookup->visible($uriVariables['slug'] ?? null), $viewer);
    }
}
