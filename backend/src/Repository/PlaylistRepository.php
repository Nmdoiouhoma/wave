<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Playlist;
use App\Entity\User;
use App\Pagination\Keyset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * DATA_MODEL §7. Item writes are SQL in the caller's transaction (positions 0-based and
 * contiguous; uniqueness of positions is deferred to commit).
 *
 * @extends ServiceEntityRepository<Playlist>
 */
final class PlaylistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Playlist::class);
    }

    public function findWithOwner(Uuid $id): ?Playlist
    {
        /* @var Playlist|null */
        return $this->createQueryBuilder('p')->addSelect('o')->join('p.owner', 'o')
            ->andWhere('p.id = :id')->setParameter('id', $id, 'uuid')
            ->getQuery()->getOneOrNullResult();
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Playlist> in the order of $ids (missing ones skipped)
     */
    public function findByIdsOrdered(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        /** @var list<Playlist> $rows */
        $rows = $this->createQueryBuilder('p')->addSelect('o')->join('p.owner', 'o')
            ->andWhere('p.id IN (:ids)')->setParameter('ids', array_map(static fn (string $id): string => $id, $ids))
            ->getQuery()->getResult();
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->getId()->toRfc4122()] = $row;
        }

        return array_values(array_filter(array_map(static fn (string $id): ?Playlist => $byId[$id] ?? null, $ids)));
    }

    /**
     * Newest first, keyset (created_at, id); fetches $limit + 1.
     *
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return list<Playlist>
     */
    public function findPageByOwner(User $owner, bool $onlyVisible, ?array $after, int $limit): array
    {
        $qb = $this->createQueryBuilder('p')->addSelect('o')->join('p.owner', 'o')
            ->andWhere('p.owner = :owner')->setParameter('owner', $owner->getId(), 'uuid')
            ->orderBy('p.createdAt', 'DESC')->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit + 1);
        if ($onlyVisible) {
            $qb->andWhere("p.isPublic = true AND p.moderation = 'visible'");
        }
        if (null !== $after) {
            [$at, $id] = Keyset::timestampAndId($after);
            $qb->andWhere('p.createdAt < :afterAt OR (p.createdAt = :afterAt AND p.id < :afterId)')
                ->setParameter('afterAt', $at, Types::DATETIMETZ_IMMUTABLE)
                ->setParameter('afterId', $id, 'uuid');
        }

        /* @var list<Playlist> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Before {@see lock()} on an append (F17): holds the track row so that a concurrent deletion,
     * which locks the track first too, either waits for the append or is seen as gone (false).
     */
    public function shareTrack(Uuid $trackId): bool
    {
        return false !== $this->db()->fetchOne('SELECT 1 FROM track WHERE id = :t FOR KEY SHARE', ['t' => $trackId->toRfc4122()]);
    }

    /** Serialises item writes on one playlist and returns its current item_count. */
    public function lock(Playlist $playlist): int
    {
        return (int) $this->db()->fetchOne('SELECT item_count FROM playlist WHERE id = :id FOR UPDATE', ['id' => $playlist->getId()->toRfc4122()]);
    }

    public function contains(Playlist $playlist, Uuid $trackId): bool
    {
        return false !== $this->db()->fetchOne(
            'SELECT 1 FROM playlist_item WHERE playlist_id = :p AND track_id = :t',
            ['p' => $playlist->getId()->toRfc4122(), 't' => $trackId->toRfc4122()],
        );
    }

    /**
     * Appends at `item_count` (caller holds the lock).
     *
     * @return array{position: int, addedAt: string}
     */
    public function append(Playlist $playlist, Uuid $trackId): array
    {
        /** @var array{position: int, added_at: string} $row */
        $row = $this->db()->fetchAssociative(
            'INSERT INTO playlist_item (id, playlist_id, track_id, position, added_at)
             SELECT :id, p.id, :t, p.item_count, clock_timestamp() FROM playlist p WHERE p.id = :p
             RETURNING position, added_at',
            ['id' => Uuid::v7()->toRfc4122(), 'p' => $playlist->getId()->toRfc4122(), 't' => $trackId->toRfc4122()],
        );
        $this->db()->executeStatement(
            'UPDATE playlist SET item_count = item_count + 1, updated_at = clock_timestamp() WHERE id = :p',
            ['p' => $playlist->getId()->toRfc4122()],
        );

        return ['position' => (int) $row['position'], 'addedAt' => $row['added_at']];
    }

    /** Removes the item and shifts the following ones; false when the track is not in it. */
    public function remove(Playlist $playlist, Uuid $trackId): bool
    {
        $position = $this->db()->fetchOne(
            'DELETE FROM playlist_item WHERE playlist_id = :p AND track_id = :t RETURNING position',
            ['p' => $playlist->getId()->toRfc4122(), 't' => $trackId->toRfc4122()],
        );
        if (false === $position) {
            return false;
        }
        $this->db()->executeStatement(
            'UPDATE playlist_item SET position = position - 1 WHERE playlist_id = :p AND position > :pos',
            ['p' => $playlist->getId()->toRfc4122(), 'pos' => (int) $position],
        );
        $this->db()->executeStatement(
            'UPDATE playlist SET item_count = item_count - 1, updated_at = clock_timestamp() WHERE id = :p',
            ['p' => $playlist->getId()->toRfc4122()],
        );

        return true;
    }

    /** @return list<string> track ids in position order */
    public function trackIds(Playlist $playlist): array
    {
        /* @var list<string> */
        return $this->db()->fetchFirstColumn(
            'SELECT track_id FROM playlist_item WHERE playlist_id = :p ORDER BY position',
            ['p' => $playlist->getId()->toRfc4122()],
        );
    }

    /** @param list<string> $trackIds the complete new order (already checked against the current set) */
    public function reorder(Playlist $playlist, array $trackIds): void
    {
        if ([] !== $trackIds) {
            $this->db()->executeStatement(
                'UPDATE playlist_item i SET position = o.ord - 1
                 FROM unnest(CAST(:ids AS uuid[])) WITH ORDINALITY AS o(track_id, ord)
                 WHERE i.playlist_id = :p AND i.track_id = o.track_id',
                ['p' => $playlist->getId()->toRfc4122(), 'ids' => '{'.implode(',', $trackIds).'}'],
            );
        }
        $this->db()->executeStatement('UPDATE playlist SET updated_at = clock_timestamp() WHERE id = :p', ['p' => $playlist->getId()->toRfc4122()]);
    }

    /**
     * Items by position (keyset on position, which only the owner changes). For anyone but the
     * owner, items whose track is not visible to the viewer are left out in SQL (W-26 F15: no
     * trackId leak; positions may show gaps).
     *
     * @return list<array{track_id: string, position: int, added_at: string}> $limit + 1 rows
     */
    public function itemsPage(Playlist $playlist, ?User $viewer, ?int $afterPosition, int $limit): array
    {
        $params = ['p' => $playlist->getId()->toRfc4122(), 'after' => $afterPosition ?? -1, 'limit' => $limit + 1];
        $visible = '';
        if (!$playlist->isOwnedBy($viewer)) {
            $visible = "AND EXISTS (SELECT 1 FROM track t WHERE t.id = i.track_id
                 AND ((t.status = 'ready' AND t.visibility = 'public' AND t.moderation = 'visible') OR t.owner_id = :v OR :mod = 1))";
            $params += [
                'v' => $viewer?->getId()->toRfc4122() ?? '00000000-0000-0000-0000-000000000000',
                'mod' => null !== $viewer && $viewer->isModerator() ? 1 : 0,
            ];
        }
        $rows = $this->db()->fetchAllAssociative(
            "SELECT i.track_id, i.position, i.added_at FROM playlist_item i
             WHERE i.playlist_id = :p AND i.position > :after {$visible} ORDER BY i.position LIMIT :limit",
            $params,
        );

        return array_map(
            static fn (array $r): array => ['track_id' => (string) $r['track_id'], 'position' => (int) $r['position'], 'added_at' => (string) $r['added_at']],
            $rows,
        );
    }

    /**
     * DATA_MODEL §7 `coverUrl`: cover of the first item visible to the viewer, for a whole page.
     *
     * @param list<Uuid> $playlistIds
     *
     * @return array<string, string|null> playlist id → cover key (null: first visible item has no cover)
     */
    public function coverKeys(array $playlistIds, ?User $viewer): array
    {
        if ([] === $playlistIds) {
            return [];
        }
        $rows = $this->db()->fetchAllKeyValue(
            "SELECT DISTINCT ON (i.playlist_id) i.playlist_id, t.cover_key
             FROM playlist_item i JOIN track t ON t.id = i.track_id
             WHERE i.playlist_id IN (:ids)
               AND ((t.status = 'ready' AND t.visibility = 'public' AND t.moderation = 'visible') OR t.owner_id = :v OR :mod = 1)
             ORDER BY i.playlist_id, i.position",
            [
                'ids' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $playlistIds),
                'v' => $viewer?->getId()->toRfc4122() ?? '00000000-0000-0000-0000-000000000000',
                'mod' => null !== $viewer && $viewer->isModerator() ? 1 : 0,
            ],
            ['ids' => ArrayParameterType::STRING],
        );

        /* @var array<string, string|null> */
        return $rows;
    }

    /**
     * Track deletion (DATA_MODEL §7): before the cascade removes the items, decrement the
     * item_count of every playlist containing the track and compact positions.
     *
     * Lock order, as on append: the track row (F17: an append to any playlist waits, or has
     * committed and is seen below), then the touched playlists by id (F11).
     */
    public function detachTrackEverywhere(Uuid $trackId): void
    {
        $t = $trackId->toRfc4122();
        $this->db()->fetchOne('SELECT 1 FROM track WHERE id = :t FOR UPDATE', ['t' => $t]);
        $this->db()->fetchFirstColumn(
            'SELECT p.id FROM playlist p
             WHERE p.id IN (SELECT playlist_id FROM playlist_item WHERE track_id = :t)
             ORDER BY p.id FOR UPDATE',
            ['t' => $t],
        );
        $this->db()->executeStatement(
            'UPDATE playlist_item x SET position = x.position - 1
             FROM playlist_item i WHERE i.track_id = :t AND x.playlist_id = i.playlist_id AND x.position > i.position',
            ['t' => $t],
        );
        $this->db()->executeStatement(
            'UPDATE playlist p SET item_count = item_count - 1, updated_at = clock_timestamp()
             FROM playlist_item i WHERE i.playlist_id = p.id AND i.track_id = :t',
            ['t' => $t],
        );
        $this->db()->executeStatement('DELETE FROM playlist_item WHERE track_id = :t', ['t' => $t]);
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
