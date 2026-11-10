<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\CommentPage;
use App\Mapper\CommentMapper;
use App\Pagination\CursorCodec;
use App\Pagination\Keyset;
use App\Pagination\PageRequest;
use App\Repository\CommentRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /posts/{postId}/comments`: post visible to the caller (DATA_MODEL §9, else 404), newest
 * first, keyset on (created_at, id). Suspended authors' comments are left out; hidden ones are
 * listed for their author and moderators only (§10).
 *
 * @implements ProviderInterface<CommentPage>
 */
final readonly class CommentListProvider implements ProviderInterface
{
    public function __construct(
        private PostLookup $posts,
        private CommentRepository $comments,
        private CurrentUser $currentUser,
        private CommentMapper $mapper,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CommentPage
    {
        $viewer = $this->currentUser->require();
        $request = $context['request'] ?? null;
        $post = $this->posts->visible($uriVariables['postId'] ?? null, $viewer);
        $page = PageRequest::fromRequest($request instanceof Request ? $request : null, $this->codec);
        $rows = $this->comments->findPage($post, $viewer, $page->after, $page->limit);
        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encode(Keyset::fromTimestampAndId($last->getCreatedAt(), $last->getId()));
        }

        return new CommentPage($this->mapper->toViews($rows, $viewer), $next);
    }
}
