<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PostPage;
use App\Mapper\PostMapper;
use App\Pagination\CursorCodec;
use App\Pagination\Keyset;
use App\Pagination\PageRequest;
use App\Repository\PostRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;

/**
 * `/users/{username}/posts`: newest first, keyset on (created_at, id). Others see playable
 * posts only; the author sees their posts whose track they can still see; moderators see all.
 *
 * @implements ProviderInterface<PostPage>
 */
final readonly class PostListProvider implements ProviderInterface
{
    public function __construct(
        private UserLookup $users,
        private PostRepository $posts,
        private CurrentUser $currentUser,
        private PostMapper $mapper,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PostPage
    {
        $viewer = $this->currentUser->require();
        $request = $context['request'] ?? null;
        $page = PageRequest::fromRequest($request instanceof Request ? $request : null, $this->codec);
        $author = $this->users->byUsername($uriVariables['username'] ?? null);
        $scope = match (true) {
            $viewer->isModerator() => 'all',
            $viewer->getId()->equals($author->getId()) => 'author',
            default => 'playable',
        };
        $rows = $this->posts->findPageByAuthor($author, $viewer, $scope, $page->after, $page->limit);
        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encode(Keyset::fromTimestampAndId($last->getCreatedAt(), $last->getId()));
        }

        return new PostPage($this->mapper->toViews($rows, $viewer), $next);
    }
}
