<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PostPage;
use App\Feed\FeedRanker;
use App\Mapper\PostMapper;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\PostRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /feed/for-you` (ADR 011): the ranker returns playable post ids in display order, this
 * provider hydrates them in that order with full `Post` payloads (clip embedded).
 *
 * @implements ProviderInterface<PostPage>
 */
final readonly class ForYouFeedProvider implements ProviderInterface
{
    public const int DEFAULT_LIMIT = 10;
    public const int MAX_LIMIT = 20;

    public function __construct(
        private FeedRanker $ranker,
        private PostRepository $posts,
        private CurrentUser $currentUser,
        private PostMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PostPage
    {
        $viewer = $this->currentUser->require();
        $request = $context['request'] ?? null;
        $request = $request instanceof Request ? $request : null;
        $cursor = $request?->query->getString('cursor') ?? '';
        $page = $this->ranker->forYou($viewer->getId(), '' === $cursor ? null : $cursor, self::limit($request));

        return new PostPage($this->mapper->toViews($this->posts->findManyInOrder($page->postIds), $viewer), $page->nextCursor);
    }

    /** Same rule and message as PageRequest (21 or 0 → 400 `malformed_request`). */
    private static function limit(?Request $request): int
    {
        $raw = $request?->query->getString('limit') ?? '';
        if ('' === $raw) {
            return self::DEFAULT_LIMIT;
        }
        if (1 !== preg_match('/^[0-9]{1,3}$/', $raw) || (int) $raw < 1 || (int) $raw > self::MAX_LIMIT) {
            throw new ApiProblemException(ProblemCode::MalformedRequest, 'limit must be an integer between 1 and '.self::MAX_LIMIT.'.');
        }

        return (int) $raw;
    }
}
