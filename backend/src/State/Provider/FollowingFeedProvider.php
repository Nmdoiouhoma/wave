<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PostPage;
use App\Entity\User;
use App\Mapper\PostMapper;
use App\Pagination\CursorCodec;
use App\Pagination\Keyset;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\PostRepository;
use App\Repository\RepostRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /feed/following` (ADR 015): one row per post at its latest activity — a post authored by a
 * followed user and playable for the caller, or a post reposted by a followed (non-suspended) user
 * when it is publicly playable, playable for the caller and not the caller's own. Newest activity
 * first, keyset on (activity time, post id); `repostedBy`/`repostedAt` describe that activity when
 * it is a repost. `limit` default 10, max 20. Phase-3 cursors (plain keyset) → 400 `invalid_cursor`.
 *
 * @implements ProviderInterface<PostPage>
 */
final readonly class FollowingFeedProvider implements ProviderInterface
{
    public const int DEFAULT_LIMIT = 10;
    public const int MAX_LIMIT = 20;
    private const string CURSOR_VERSION = 'f2';

    public function __construct(
        private RepostRepository $reposts,
        private PostRepository $posts,
        private CurrentUser $currentUser,
        private PostMapper $mapper,
        private CursorCodec $codec,
        private EntityManagerInterface $em,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PostPage
    {
        $viewer = $this->currentUser->require();
        $request = $context['request'] ?? null;
        $limit = $this->limit($request instanceof Request ? $request : null);
        $after = $this->after($request instanceof Request ? $request : null);

        $rows = $this->reposts->followingActivityPage($viewer, $after, $limit);
        $next = null;
        if (\count($rows) > $limit) {
            $rows = \array_slice($rows, 0, $limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encodePayload([
                'v' => self::CURSOR_VERSION,
                'at' => new \DateTimeImmutable($last['at'])->format(Keyset::TIMESTAMP_FORMAT),
                'id' => $last['post_id'],
            ]);
        }

        $orderedIds = array_map(static fn (array $r): Uuid => Uuid::fromString($r['post_id']), $rows);
        $postsById = [];
        foreach ($this->posts->findManyInOrder($orderedIds) as $post) {
            $postsById[$post->getId()->toRfc4122()] = $post;
        }
        $reposters = $this->reposters($rows);

        $orderedPosts = [];
        $attribution = [];
        foreach ($rows as $row) {
            $post = $postsById[$row['post_id']] ?? null;
            if (null === $post) {
                continue;
            }
            $orderedPosts[] = $post;
            if (null !== $row['reposter_id'] && isset($reposters[$row['reposter_id']])) {
                $attribution[$row['post_id']] = ['by' => $reposters[$row['reposter_id']], 'at' => new \DateTimeImmutable($row['at'])];
            }
        }

        return new PostPage($this->mapper->toViews($orderedPosts, $viewer, $attribution), $next);
    }

    private function limit(?Request $request): int
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

    /** @return list<string>|null the (timestamp, id) keyset of the previous page */
    private function after(?Request $request): ?array
    {
        $cursor = $request?->query->getString('cursor') ?? '';
        if ('' === $cursor) {
            return null;
        }
        $data = $this->codec->decodePayload($cursor);
        if (self::CURSOR_VERSION !== ($data['v'] ?? null) || !\is_string($data['at'] ?? null) || !\is_string($data['id'] ?? null)) {
            throw CursorCodec::invalid();
        }

        return [$data['at'], $data['id']];
    }

    /**
     * @param list<array{post_id: string, at: string, reposter_id: ?string}> $rows
     *
     * @return array<string, User>
     */
    private function reposters(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            if (null !== $row['reposter_id']) {
                $ids[$row['reposter_id']] = Uuid::fromString($row['reposter_id']);
            }
        }
        if ([] === $ids) {
            return [];
        }
        $byId = [];
        foreach ($this->em->getRepository(User::class)->findBy(['id' => array_values($ids)]) as $user) {
            $byId[$user->getId()->toRfc4122()] = $user;
        }

        return $byId;
    }
}
