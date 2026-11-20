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
use App\Problem\ApiProblemException;
use App\Repository\HashtagRepository;
use App\Repository\PostRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /hashtags/{name}/posts` (W-41): posts with this hashtag that are playable for the caller
 * (moderators included: a hashtag page is a public surface), newest first, keyset on
 * (created_at, id). Same 404 rule as {@see HashtagProvider}.
 *
 * @implements ProviderInterface<PostPage>
 */
final readonly class HashtagPostsProvider implements ProviderInterface
{
    public function __construct(
        private HashtagRepository $hashtags,
        private PostRepository $posts,
        private PostMapper $mapper,
        private CurrentUser $currentUser,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PostPage
    {
        $viewer = $this->currentUser->require();
        $request = $context['request'] ?? null;
        $page = PageRequest::fromRequest($request instanceof Request ? $request : null, $this->codec);
        if (null !== $page->after) {
            Keyset::timestampAndId($page->after); // malformed keyset → 400 before any lookup
        }
        $hashtag = $this->hashtags->findVisibleByName($uriVariables['name'] ?? null, $viewer->getId())
            ?? throw ApiProblemException::notFound();

        $rows = $this->posts->findPlayablePageByHashtag(Uuid::fromString($hashtag['id']), $viewer, $page->after, $page->limit);
        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encode(Keyset::fromTimestampAndId($last->getCreatedAt(), $last->getId()));
        }

        return new PostPage($this->mapper->toViews($rows, $viewer), $next);
    }
}
