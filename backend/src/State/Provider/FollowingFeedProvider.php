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
 * `GET /feed/following` (W-32): playable posts of followed, non-suspended users, newest first
 * (seen posts included), keyset on (created_at, id); `limit` default 10, max 20.
 *
 * @implements ProviderInterface<PostPage>
 */
final readonly class FollowingFeedProvider implements ProviderInterface
{
    public const int DEFAULT_LIMIT = 10;
    public const int MAX_LIMIT = 20;

    public function __construct(
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
        $page = PageRequest::fromRequest($request instanceof Request ? $request : null, $this->codec, self::DEFAULT_LIMIT, self::MAX_LIMIT);
        $rows = $this->posts->findFollowingPage($viewer, $page->after, $page->limit);
        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encode(Keyset::fromTimestampAndId($last->getCreatedAt(), $last->getId()));
        }

        return new PostPage($this->mapper->toViews($rows, $viewer), $next);
    }
}
