<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\TrackUpload;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/** @extends ServiceEntityRepository<TrackUpload> */
final class TrackUploadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackUpload::class);
    }

    public function countPending(User $user): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT count(*) FROM track_upload WHERE user_id = :u AND status = 'pending'",
            ['u' => $user->getId()->toRfc4122()],
        );
    }

    /**
     * Single-use consumption (DATA_MODEL §6b): flips `pending` → `consumed` only if the
     * upload belongs to $user and is younger than the 2 h window. Run it inside the
     * track-creation transaction. Returns false when nothing was consumed.
     */
    public function consume(Uuid $uploadId, User $user, Uuid $trackId, \DateTimeImmutable $now): bool
    {
        $limit = $now->sub(new \DateInterval(TrackUpload::CONSUME_WINDOW));

        return 1 === $this->getEntityManager()->getConnection()->executeStatement(
            "UPDATE track_upload SET status = 'consumed', consumed_at = :now, track_id = :t
             WHERE id = :id AND user_id = :u AND status = 'pending' AND created_at > :limit",
            [
                'now' => $now->format('Y-m-d H:i:s.uP'),
                't' => $trackId->toRfc4122(),
                'id' => $uploadId->toRfc4122(),
                'u' => $user->getId()->toRfc4122(),
                'limit' => $limit->format('Y-m-d H:i:s.uP'),
            ],
        );
    }

    /**
     * For CleanupQuarantine (media-infra): marks `pending` uploads older than $before as
     * `expired` and returns their quarantine keys (objects to delete).
     *
     * @return list<string>
     */
    public function expirePendingCreatedBefore(\DateTimeImmutable $before): array
    {
        /* @var list<string> */
        return $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "UPDATE track_upload SET status = 'expired' WHERE status = 'pending' AND created_at < :before RETURNING quarantine_key",
            ['before' => $before->format('Y-m-d H:i:s.uP')],
        );
    }

    /** For CleanupQuarantine: rows older than $before (7 days) are deleted. */
    public function deleteCreatedBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM track_upload WHERE created_at < :before',
            ['before' => $before->format('Y-m-d H:i:s.uP')],
        );
    }
}
