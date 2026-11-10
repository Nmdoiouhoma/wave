<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Artist;
use App\Entity\Track;
use App\Entity\User;
use App\Pagination\Keyset;
use App\Security\TrackAudience;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<Track> */
final class TrackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Track::class);
    }

    public function findWithRelations(Uuid $id): ?Track
    {
        /* @var Track|null */
        return $this->createQueryBuilder('t')
            ->addSelect('a', 'o', 'g')
            ->join('t.artist', 'a')->join('t.owner', 'o')->join('t.genre', 'g')
            ->andWhere('t.id = :id')->setParameter('id', $id, 'uuid')
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, Track> by id, relations fetch-joined (one query)
     */
    public function findByIdsWithRelations(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        /** @var list<Track> $rows */
        $rows = $this->createQueryBuilder('t')
            ->addSelect('a', 'o', 'g')
            ->join('t.artist', 'a')->join('t.owner', 'o')->join('t.genre', 'g')
            ->andWhere('t.id IN (:ids)')->setParameter('ids', $ids)
            ->getQuery()->getResult();
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->getId()->toRfc4122()] = $row;
        }

        return $byId;
    }

    public function countByArtist(Artist $artist): int
    {
        return $this->count(['artist' => $artist]);
    }

    /**
     * Newest first, keyset on (created_at, id); fetches $limit + 1 rows. $seesAll (owner,
     * moderator): every track; otherwise the tracks visible to $viewer (TrackAudience, §6).
     *
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return list<Track>
     */
    public function findPageByOwner(User $owner, ?User $viewer, bool $seesAll, ?array $after, int $limit): array
    {
        $qb = $this->pageQuery($viewer, $seesAll, $after, $limit)
            ->andWhere('t.owner = :owner')->setParameter('owner', $owner->getId(), 'uuid');

        /* @var list<Track> */
        return $qb->getQuery()->getResult();
    }

    /**
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return list<Track>
     */
    public function findPageByArtist(Artist $artist, ?User $viewer, bool $seesAll, ?array $after, int $limit): array
    {
        $qb = $this->pageQuery($viewer, $seesAll, $after, $limit)
            ->andWhere('t.artist = :artist')->setParameter('artist', $artist->getId(), 'uuid');

        /* @var list<Track> */
        return $qb->getQuery()->getResult();
    }

    /** @param list<string|int|float|bool|null>|null $after */
    private function pageQuery(?User $viewer, bool $seesAll, ?array $after, int $limit): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('a', 'o', 'g')
            ->join('t.artist', 'a')->join('t.owner', 'o')->join('t.genre', 'g')
            ->orderBy('t.createdAt', 'DESC')->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limit + 1);
        if (!$seesAll) {
            $qb->andWhere(TrackAudience::visibleDql('t'));
            TrackAudience::bindDql($qb, $viewer);
        }
        if (null !== $after) {
            [$at, $id] = Keyset::timestampAndId($after);
            $qb->andWhere('t.createdAt < :afterAt OR (t.createdAt = :afterAt AND t.id < :afterId)')
                ->setParameter('afterAt', $at, Types::DATETIMETZ_IMMUTABLE)
                ->setParameter('afterId', $id, 'uuid');
        }

        return $qb;
    }
}
