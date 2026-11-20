<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\HashtagView;
use App\Problem\ApiProblemException;
use App\Repository\HashtagRepository;
use App\Security\CurrentUser;

/**
 * `GET /hashtags/{name}` (W-41). 404 for an unknown or invalid name and for a hashtag none of
 * whose posts is playable for the caller (no existence oracle on restricted posts).
 *
 * @implements ProviderInterface<HashtagView>
 */
final readonly class HashtagProvider implements ProviderInterface
{
    public function __construct(private HashtagRepository $hashtags, private CurrentUser $currentUser)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): HashtagView
    {
        $viewer = $this->currentUser->require();
        $hashtag = $this->hashtags->findVisibleByName($uriVariables['name'] ?? null, $viewer->getId())
            ?? throw ApiProblemException::notFound();

        return new HashtagView($hashtag['name'], $hashtag['postCount']);
    }
}
