<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Post;
use App\Entity\User;
use App\Pagination\Keyset;
use App\Security\TrackAudience;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Post> */
final class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    /**
     * DATA_MODEL §9 ready transition, for the two independent media handlers (W-31): each one
     * flushes its key (Post::setClipKey / Post::setVisual), then calls this. The UPDATE is a
     * no-op until both keys are present, and never resurrects a failed post.
     * Returns true when this call moved the post to ready. Callers holding the Post entity
     * should refresh it afterwards (the UPDATE bypasses the unit of work).
     */
    public function tryMarkReady(Uuid $postId): bool
    {
        return 1 === (int) $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE post SET status = 'ready' WHERE id = :id AND status = 'processing' AND clip_key IS NOT NULL AND (visual_type IS NULL OR visual_key IS NOT NULL)",
            ['id' => $postId->toRfc4122()],
        );
    }

    /** Post with author, track and the track's relations fetch-joined (one query). */
    public function findWithRelations(Uuid $id): ?Post
    {
        /* @var Post|null */
        return $this->withRelations()
            ->andWhere('p.id = :id')->setParameter('id', $id, 'uuid')
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * `/users/{username}/posts`, newest first, keyset on (created_at, id); fetches $limit + 1 rows.
     * DATA_MODEL §9: moderators see every post, the author their posts whose track they can
     * still see (§6), everyone else the posts playable for them (viewer-dependent, FB-18).
     *
     * @param 'all'|'author'|'playable'             $scope
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return list<Post>
     */
    public function findPageByAuthor(User $author, User $viewer, string $scope, ?array $after, int $limit): array
    {
        $qb = $this->withRelations()
            ->andWhere('p.author = :author')->setParameter('author', $author->getId(), 'uuid')
            ->orderBy('p.createdAt', 'DESC')->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit + 1);
        if ('author' === $scope) {
            $qb->andWhere(TrackAudience::visibleDql('t'));
            TrackAudience::bindDql($qb, $author);
        } elseif ('playable' === $scope) {
            $qb->andWhere("p.status = 'ready' AND p.moderation = 'visible' AND t.moderation = 'visible' AND u.suspendedAt IS NULL")
                ->andWhere("(t.visibility = 'public' AND t.allowClips = true) OR (t.owner = p.author AND ".TrackAudience::dql('t').')');
            TrackAudience::bindDql($qb, $viewer);
        }
        if (null !== $after) {
            [$at, $id] = Keyset::timestampAndId($after);
            $qb->andWhere('p.createdAt < :afterAt OR (p.createdAt = :afterAt AND p.id < :afterId)')
                ->setParameter('afterAt', $at, Types::DATETIMETZ_IMMUTABLE)
                ->setParameter('afterId', $id, 'uuid');
        }

        /* @var list<Post> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Hashtag names of each post, sorted by name — one query for a whole page.
     *
     * @param list<Uuid> $postIds
     *
     * @return array<string, list<string>> post id => names
     */
    public function hashtagsOf(array $postIds): array
    {
        if ([] === $postIds) {
            return [];
        }
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT ph.post_id, h.name FROM post_hashtag ph JOIN hashtag h ON h.id = ph.hashtag_id
             WHERE ph.post_id IN (:ids) ORDER BY h.name',
            ['ids' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $postIds)],
            ['ids' => ArrayParameterType::STRING],
        );
        $byPost = [];
        foreach ($rows as $row) {
            $byPost[(string) $row['post_id']][] = (string) $row['name'];
        }

        return $byPost;
    }

    private function withRelations(): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->addSelect('u', 't', 'a', 'o', 'g')
            ->join('p.author', 'u')->join('p.track', 't')
            ->join('t.artist', 'a')->join('t.owner', 'o')->join('t.genre', 'g');
    }
}
