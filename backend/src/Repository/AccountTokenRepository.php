<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Enum\AccountTokenPurpose;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<AccountToken> */
final class AccountTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountToken::class);
    }

    public function findOneByHash(string $hash): ?AccountToken
    {
        return $this->findOneBy(['tokenHash' => $hash]);
    }

    /** A newer token voids older unused ones of the same purpose (ADR 007). */
    public function deleteUnused(User $user, AccountTokenPurpose $purpose): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM account_token WHERE user_id = :u AND purpose = :p AND used_at IS NULL',
            ['u' => $user->getId()->toRfc4122(), 'p' => $purpose->value],
        );
    }

    public function purgeOlderThan(\DateTimeImmutable $limit): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM account_token WHERE created_at < :limit',
            ['limit' => $limit->format('Y-m-d H:i:sP')],
        );
    }
}
