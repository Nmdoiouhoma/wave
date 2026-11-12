<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Report;
use App\Entity\User;
use App\Enum\ReportStatus;
use App\Enum\ReportTargetType;
use App\Pagination\Keyset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * Reports (DATA_MODEL §13). Writes are native SQL inside the caller's transaction, under the
 * target's advisory lock (lockTarget()), so concurrent reporters and moderators serialise per target.
 *
 * @phpstan-type TargetRow array{target_type: string, target_id: string, n: int, first_at: \DateTimeImmutable, last_at: \DateTimeImmutable}
 *
 * @extends ServiceEntityRepository<Report>
 */
final class ReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Report::class);
    }

    /** `pg_advisory_xact_lock(hashtext(target_type || ':' || target_id))`; call inside a transaction. */
    public function lockTarget(ReportTargetType $type, Uuid $id): void
    {
        $this->db()->executeQuery('SELECT pg_advisory_xact_lock(hashtext(:k))', ['k' => $type->value.':'.$id->toRfc4122()]);
    }

    /**
     * Deletes every report on one target (polymorphic, no FK, DATA_MODEL §13): call in the
     * target's deletion transaction so no orphan stays in the moderation queue.
     */
    public function deleteForTarget(ReportTargetType $type, Uuid $id): int
    {
        return (int) $this->db()->executeStatement(
            'DELETE FROM report WHERE target_type = :tt AND target_id = :ti',
            ['tt' => $type->value, 'ti' => $id->toRfc4122()],
        );
    }

    /**
     * For `DELETE /me` (phase 5), in its transaction before the user row goes: reports on the
     * user and on what the FK cascade removes with them (playlists, artists, tracks, comments).
     * Reports on posts (and their comments) go with `PostCleanup::beforeUserDeletion`; reports
     * filed by the user go by FK cascade on `reporter_id`.
     */
    public function deleteForUserContent(Uuid $userId): int
    {
        return (int) $this->db()->executeStatement(
            "DELETE FROM report WHERE (target_type = 'user' AND target_id = :u)
                OR (target_type = 'playlist' AND target_id IN (SELECT id FROM playlist WHERE owner_id = :u))
                OR (target_type = 'artist' AND target_id IN (SELECT id FROM artist WHERE owner_id = :u))
                OR (target_type = 'track' AND target_id IN (SELECT id FROM track WHERE owner_id = :u))
                OR (target_type = 'comment' AND target_id IN (SELECT id FROM comment WHERE author_id = :u))",
            ['u' => $userId->toRfc4122()],
        );
    }

    /** Inserts an open report; false when this reporter already reported the target (unique key). */
    public function insertOpen(Report $report): bool
    {
        $id = $this->db()->fetchOne(
            "INSERT INTO report (id, reporter_id, target_type, target_id, reason, details, status, created_at)
             VALUES (:id, :r, :tt, :ti, :reason, :details, 'open', :at)
             ON CONFLICT (reporter_id, target_type, target_id) DO NOTHING RETURNING id",
            [
                'id' => $report->getId()->toRfc4122(),
                'r' => $report->getReporter()->getId()->toRfc4122(),
                'tt' => $report->getTargetType()->value,
                'ti' => $report->getTargetId()->toRfc4122(),
                'reason' => $report->getReason()->value,
                'details' => $report->getDetails(),
                'at' => $report->getCreatedAt(),
            ],
            ['at' => Types::DATETIMETZ_IMMUTABLE],
        );

        return false !== $id;
    }

    public function countOpenReporters(ReportTargetType $type, Uuid $id): int
    {
        return (int) $this->db()->fetchOne(
            "SELECT count(DISTINCT reporter_id) FROM report WHERE target_type = :tt AND target_id = :ti AND status = 'open'",
            ['tt' => $type->value, 'ti' => $id->toRfc4122()],
        );
    }

    /** Closes every open report of the target with the moderator's decision; returns how many. */
    public function closeOpen(ReportTargetType $type, Uuid $id, ReportStatus $status, User $moderator, ?string $note): int
    {
        return (int) $this->db()->executeStatement(
            "UPDATE report SET status = :s, handled_by_id = :m, handled_at = now(), decision_note = :n
             WHERE target_type = :tt AND target_id = :ti AND status = 'open'",
            ['s' => $status->value, 'm' => $moderator->getId()->toRfc4122(), 'n' => $note, 'tt' => $type->value, 'ti' => $id->toRfc4122()],
        );
    }

    public function existsForTarget(ReportTargetType $type, Uuid $id): bool
    {
        return false !== $this->db()->fetchOne(
            'SELECT 1 FROM report WHERE target_type = :tt AND target_id = :ti LIMIT 1',
            ['tt' => $type->value, 'ti' => $id->toRfc4122()],
        );
    }

    /**
     * Moderation queue: targets with reports of $status, most reported first then oldest first,
     * keyset on (n DESC, first_at ASC, target_type, target_id); fetches $limit + 1 rows.
     *
     * @param array{int, \DateTimeImmutable, string, Uuid}|null $after
     *
     * @return list<TargetRow>
     */
    public function findTargetPage(ReportStatus $status, ?ReportTargetType $type, ?array $after, int $limit): array
    {
        $params = ['s' => $status->value, 'lim' => $limit + 1];
        $types = ['lim' => ParameterType::INTEGER];
        $where = 'status = :s';
        if (null !== $type) {
            $where .= ' AND target_type = :tt';
            $params['tt'] = $type->value;
        }
        $having = '';
        if (null !== $after) {
            $having = 'HAVING count(*) < :an OR (count(*) = :an AND (min(created_at) > :af
                OR (min(created_at) = :af AND (target_type > :att OR (target_type = :att AND target_id > :ati)))))';
            $params += ['an' => $after[0], 'af' => $after[1], 'att' => $after[2], 'ati' => $after[3]->toRfc4122()];
            $types += ['an' => ParameterType::INTEGER, 'af' => Types::DATETIMETZ_IMMUTABLE];
        }
        $rows = $this->db()->fetchAllAssociative(
            "SELECT target_type, target_id, count(*) AS n, min(created_at) AS first_at, max(created_at) AS last_at
             FROM report WHERE $where GROUP BY target_type, target_id $having
             ORDER BY n DESC, first_at ASC, target_type ASC, target_id ASC LIMIT :lim",
            $params,
            $types,
        );

        return array_map(self::targetRow(...), $rows);
    }

    /**
     * Aggregate of one target's reports of $status (null when it has none).
     *
     * @return TargetRow|null
     */
    public function findTarget(ReportTargetType $type, Uuid $id, ReportStatus $status): ?array
    {
        $row = $this->db()->fetchAssociative(
            'SELECT target_type, target_id, count(*) AS n, min(created_at) AS first_at, max(created_at) AS last_at
             FROM report WHERE target_type = :tt AND target_id = :ti AND status = :s GROUP BY target_type, target_id',
            ['tt' => $type->value, 'ti' => $id->toRfc4122(), 's' => $status->value],
        );

        return false === $row ? null : self::targetRow($row);
    }

    /**
     * Count per reason of the reports of $status, for a page of targets (one query).
     *
     * @param list<string> $targetIds
     *
     * @return array<string, array<string, int>> "type:id" => reason => count
     */
    public function reasonCounts(ReportStatus $status, array $targetIds): array
    {
        if ([] === $targetIds) {
            return [];
        }
        $rows = $this->db()->fetchAllAssociative(
            'SELECT target_type, target_id, reason, count(*) AS n FROM report
             WHERE status = :s AND target_id IN (:ids) GROUP BY target_type, target_id, reason ORDER BY reason',
            ['s' => $status->value, 'ids' => $targetIds],
            ['ids' => ArrayParameterType::STRING],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['target_type'].':'.$row['target_id']][(string) $row['reason']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Reports of one target, newest first, keyset on (created_at, id); fetches $limit + 1.
     *
     * @param list<string|int|float|bool|null>|null $after
     *
     * @return list<Report>
     */
    public function findPageForTarget(ReportTargetType $type, Uuid $id, ?array $after, int $limit): array
    {
        $qb = $this->createQueryBuilder('r')
            ->addSelect('rr', 'h')
            ->join('r.reporter', 'rr')->leftJoin('r.handledBy', 'h')
            ->andWhere('r.targetType = :tt')->setParameter('tt', $type->value)
            ->andWhere('r.targetId = :ti')->setParameter('ti', $id, 'uuid')
            ->orderBy('r.createdAt', 'DESC')->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit + 1);
        if (null !== $after) {
            [$at, $afterId] = Keyset::timestampAndId($after);
            $qb->andWhere('r.createdAt < :afterAt OR (r.createdAt = :afterAt AND r.id < :afterId)')
                ->setParameter('afterAt', $at, Types::DATETIMETZ_IMMUTABLE)
                ->setParameter('afterId', $afterId, 'uuid');
        }

        /* @var list<Report> */
        return $qb->getQuery()->getResult();
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return TargetRow
     */
    private static function targetRow(array $row): array
    {
        return [
            'target_type' => (string) $row['target_type'],
            'target_id' => (string) $row['target_id'],
            'n' => (int) $row['n'],
            'first_at' => new \DateTimeImmutable((string) $row['first_at']),
            'last_at' => new \DateTimeImmutable((string) $row['last_at']),
        ];
    }

    private function db(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }
}
