<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Comment;
use App\Entity\Post;
use App\Entity\User;
use App\Pagination\Keyset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Comments (DATA_MODEL §10). `post.comment_count` counts visible comments: +1 on create, −1 on
 * the delete of a visible one, moved by atomic SQL in the caller's transaction.
 *
 * @extends ServiceEntityRepository<Comment>
 */
final class CommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Comment::class);
    }

    /** Comment with its post (and the post's author/track/owner) and author fetch-joined. */
    public function findWithRelations(Uuid $id): ?Comment
    {
        /* @var Comment|null */
        return $this->createQueryBuilder('c')
            ->addSelect('ca', 'p', 'pa', 't', 'o')
            ->join('c.author', 'ca')->join('c.post', 'p')->join('p.author', 'pa')
            ->join('p.track', 't')->join('t.owner', 'o')
            ->andWhere('c.id = :id')->setParameter('id', $id, 'uuid')
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * Newest first, keyset on (created_at, id); fetches $limit + 1 rows. Comments of suspended
     * authors are left out for everyone; hidden ones are kept for their author and moderators.
     *
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return list<Comment>
     */
    public function findPage(Post $post, User $viewer, ?array $after, int $limit): array
    {
        $qb = $this->createQueryBuilder('c')
            ->addSelect('ca')
            ->join('c.author', 'ca')
            ->andWhere('c.post = :post')->setParameter('post', $post->getId(), 'uuid')
            ->andWhere('ca.suspendedAt IS NULL')
            ->orderBy('c.createdAt', 'DESC')->addOrderBy('c.id', 'DESC')
            ->setMaxResults($limit + 1);
        if (!$viewer->isModerator()) {
            $qb->andWhere("c.moderation = 'visible' OR c.author = :viewer")->setParameter('viewer', $viewer->getId(), 'uuid');
        }
        if (null !== $after) {
            [$at, $id] = Keyset::timestampAndId($after);
            $qb->andWhere('c.createdAt < :afterAt OR (c.createdAt = :afterAt AND c.id < :afterId)')
                ->setParameter('afterAt', $at, Types::DATETIMETZ_IMMUTABLE)
                ->setParameter('afterId', $id, 'uuid');
        }

        /* @var list<Comment> */
        return $qb->getQuery()->getResult();
    }

    /** Inserts the comment and bumps the post's counter; call inside a transaction. */
    public function create(Comment $comment): void
    {
        $db = $this->getEntityManager()->getConnection();
        $db->executeStatement(
            "INSERT INTO comment (id, post_id, author_id, body, moderation, created_at) VALUES (:id, :p, :a, :b, 'visible', :at)",
            [
                'id' => $comment->getId()->toRfc4122(),
                'p' => $comment->getPost()->getId()->toRfc4122(),
                'a' => $comment->getAuthor()->getId()->toRfc4122(),
                'b' => $comment->getBody(),
                'at' => $comment->getCreatedAt(),
            ],
            ['at' => Types::DATETIMETZ_IMMUTABLE],
        );
        $db->executeStatement('UPDATE post SET comment_count = comment_count + 1 WHERE id = :p', ['p' => $comment->getPost()->getId()->toRfc4122()]);
    }

    /**
     * Deletes the comment, the reports targeting it, and decrements the counter if it was visible.
     * Call inside a transaction; a concurrent delete of the same comment moves the counter once.
     */
    public function delete(Uuid $id): void
    {
        $db = $this->getEntityManager()->getConnection();
        $row = $db->fetchAssociative('DELETE FROM comment WHERE id = :id RETURNING post_id, moderation', ['id' => $id->toRfc4122()]);
        if (false === $row) {
            return;
        }
        $db->executeStatement("DELETE FROM report WHERE target_type = 'comment' AND target_id = :id", ['id' => $id->toRfc4122()]);
        if ('visible' === $row['moderation']) {
            $db->executeStatement('UPDATE post SET comment_count = comment_count - 1 WHERE id = :p', ['p' => $row['post_id']]);
        }
    }
}
