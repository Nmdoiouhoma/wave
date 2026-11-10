<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\CloseFriendItem;
use App\ApiResource\CloseFriendPage;
use App\Mapper\UserMapper;
use App\Pagination\CursorCodec;
use App\Pagination\Keyset;
use App\Pagination\PageRequest;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\CloseFriendRepository;
use App\Repository\UserRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /me/close-friends?scope=members|followers` (FB-18): the caller's own list only. The
 * cursor carries the scope as its first key, so a cursor of one scope is refused by the other.
 *
 * @implements ProviderInterface<CloseFriendPage>
 */
final readonly class CloseFriendListProvider implements ProviderInterface
{
    private const array SCOPES = ['members', 'followers'];

    public function __construct(
        private CloseFriendRepository $closeFriends,
        private UserRepository $users,
        private UserMapper $mapper,
        private CurrentUser $currentUser,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CloseFriendPage
    {
        $me = $this->currentUser->require();
        $request = $context['request'] ?? null;
        $request = $request instanceof Request ? $request : null;
        $scope = $request?->query->all()['scope'] ?? 'members';
        if (!\is_string($scope) || !\in_array($scope, self::SCOPES, true)) {
            throw new ApiProblemException(ProblemCode::MalformedRequest, 'scope must be members or followers.');
        }
        $page = PageRequest::fromRequest($request, $this->codec);
        $after = null;
        if (null !== $page->after) {
            if (($page->after[0] ?? null) !== $scope) {
                throw CursorCodec::invalid();
            }
            $after = \array_slice($page->after, 1);
        }

        $rows = $this->closeFriends->page($me, 'members' === $scope, $after, $page->limit);
        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encode([$scope, ...Keyset::fromTimestampAndId(new \DateTimeImmutable($last['at']), Uuid::fromString($last['id']))]);
        }

        $close = array_column($rows, 'close', 'id');
        $items = [];
        foreach ($this->users->findByIdsOrdered(array_column($rows, 'id')) as $user) {
            $summary = $this->mapper->toSummary($user);
            $items[] = new CloseFriendItem($summary->id, $summary->username, $summary->displayName, $summary->avatarUrl, $close[$summary->id] ?? false);
        }

        return new CloseFriendPage($items, $next);
    }
}
