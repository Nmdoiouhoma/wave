<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Artist;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Artist> */
final class ArtistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Artist::class);
    }

    public function findOneBySlug(string $slug): ?Artist
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /** @return list<Artist> oldest first */
    public function findByOwner(User $owner, bool $includeHidden): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.owner = :owner')->setParameter('owner', $owner->getId(), 'uuid')
            ->orderBy('a.createdAt', 'ASC')->addOrderBy('a.id', 'ASC');
        if (!$includeHidden) {
            $qb->andWhere("a.moderation = 'visible'");
        }

        /* @var list<Artist> */
        return $qb->getQuery()->getResult();
    }

    public function countByOwner(User $owner): int
    {
        return $this->count(['owner' => $owner]);
    }

    /** @return list<string> existing slugs equal to $base or of the form "$base-N" */
    public function findSlugsLike(string $base): array
    {
        /* @var list<string> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT slug FROM artist WHERE slug = :base OR slug ~ :pattern',
            ['base' => $base, 'pattern' => '^'.preg_quote($base, '/').'-[0-9]+$'],
        );
    }

    /**
     * DATA_MODEL §4 recount: public, ready, visible tracks. Called inside the flush
     * transaction by TrackCountListener (covers Track::markReady from media handlers).
     */
    public function recountTracks(Uuid $artistId): int
    {
        $count = $this->getEntityManager()->getConnection()->fetchOne(
            "UPDATE artist SET track_count = (
                SELECT count(*) FROM track t
                WHERE t.artist_id = :id AND t.status = 'ready' AND t.visibility = 'public' AND t.moderation = 'visible'
            ) WHERE id = :id RETURNING track_count",
            ['id' => $artistId->toRfc4122()],
        );

        return false === $count ? 0 : (int) $count;
    }
}
