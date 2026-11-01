<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\UserPage;
use App\Entity\User;
use App\Mapper\UserMapper;
use App\Pagination\CursorCodec;
use App\Pagination\Keyset;
use App\Pagination\PageRequest;
use App\Repository\FollowRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `/users/{username}/followers|following`: follow date desc, keyset (created_at, user id);
 * items are `UserListItem` with `isFollowedByMe` batch-loaded.
 *
 * @implements ProviderInterface<UserPage>
 */
final readonly class FollowListProvider implements ProviderInterface
{
    public function __construct(
        private UserLookup $lookup,
        private UserRepository $users,
        private FollowRepository $follows,
        private UserMapper $mapper,
        private CurrentUser $currentUser,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): UserPage
    {
        $user = $this->lookup->byUsername($uriVariables['username'] ?? null);
        $request = $context['request'] ?? null;
        $page = PageRequest::fromRequest($request instanceof Request ? $request : null, $this->codec);

        $rows = $this->follows->page($user, 'listFollowers' === $operation->getName(), $page->after, $page->limit);
        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encode(Keyset::fromTimestampAndId(new \DateTimeImmutable($last['at']), Uuid::fromString($last['id'])));
        }

        return new UserPage($this->mapper->toListItems($this->users->findByIdsOrdered(array_column($rows, 'id')), $this->currentUser->get()), $next);
    }
}
