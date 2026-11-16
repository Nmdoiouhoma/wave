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
use App\Repository\RepostRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `/users/{username}/reposts` (ADR 015): posts reposted by the profile user, newest repost first,
 * keyset on (repost time, post id). Only reposts whose post is still publicly playable and playable
 * for the caller are listed (live filter, nothing rewritten). `repostedBy` is the profile user,
 * `repostedAt` the repost time. Suspended user → 404 like every `/users/{username}` route.
 *
 * @implements ProviderInterface<PostPage>
 */
final readonly class UserRepostsProvider implements ProviderInterface
{
    public function __construct(
        private UserLookup $users,
        private RepostRepository $reposts,
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

        $rows = $this->reposts->userRepostsPage($author, $viewer, $page->after, $page->limit);
        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encode(Keyset::fromTimestampAndId(new \DateTimeImmutable($last['at']), Uuid::fromString($last['id'])));
        }

        $orderedIds = array_map(static fn (array $r): Uuid => Uuid::fromString($r['id']), $rows);
        $postsById = [];
        foreach ($this->posts->findManyInOrder($orderedIds) as $post) {
            $postsById[$post->getId()->toRfc4122()] = $post;
        }

        $orderedPosts = [];
        $attribution = [];
        foreach ($rows as $row) {
            $post = $postsById[$row['id']] ?? null;
            if (null === $post) {
                continue;
            }
            $orderedPosts[] = $post;
            $attribution[$row['id']] = ['by' => $author, 'at' => new \DateTimeImmutable($row['at'])];
        }

        return new PostPage($this->mapper->toViews($orderedPosts, $viewer, $attribution), $next);
    }
}
