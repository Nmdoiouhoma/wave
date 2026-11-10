<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PostView;
use App\Mapper\PostMapper;
use App\Security\CurrentUser;

/**
 * `GET /posts/{postId}`: DATA_MODEL §9 visibility (404 identical to an unknown id).
 *
 * @implements ProviderInterface<PostView>
 */
final readonly class PostProvider implements ProviderInterface
{
    public function __construct(private PostLookup $lookup, private CurrentUser $currentUser, private PostMapper $mapper)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PostView
    {
        $viewer = $this->currentUser->require();

        return $this->mapper->toView($this->lookup->visible($uriVariables['postId'] ?? null, $viewer), $viewer);
    }
}
