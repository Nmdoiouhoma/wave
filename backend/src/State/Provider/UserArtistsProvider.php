<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ArtistView;
use App\Mapper\ArtistMapper;
use App\Repository\ArtistRepository;
use App\Security\CurrentUser;

/**
 * `GET /users/{username}/artists`: oldest first, not paginated (max 10); hidden ones for the owner only.
 *
 * @implements ProviderInterface<ArtistView>
 */
final readonly class UserArtistsProvider implements ProviderInterface
{
    public function __construct(
        private UserLookup $users,
        private ArtistRepository $artists,
        private CurrentUser $currentUser,
        private ArtistMapper $mapper,
    ) {
    }

    /** @return list<ArtistView> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $owner = $this->users->byUsername($uriVariables['username'] ?? null);
        $viewer = $this->currentUser->get();
        $seesHidden = null !== $viewer && ($viewer->getId()->equals($owner->getId()) || $viewer->isModerator());

        return array_map(fn ($a) => $this->mapper->toView($a, $viewer), $this->artists->findByOwner($owner, $seesHidden));
    }
}
