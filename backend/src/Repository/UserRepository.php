<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<User> */
final class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /** citext columns: comparisons are case-insensitive in SQL. */
    public function findOneByEmail(string $email): ?User
    {
        return $this->findOneBy(['email' => $email]);
    }

    public function findOneByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => $username]);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<User> in the order of $ids (one query)
     */
    public function findByIdsOrdered(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        /** @var list<User> $rows */
        $rows = $this->createQueryBuilder('u')->andWhere('u.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->getId()->toRfc4122()] = $row;
        }

        return array_values(array_filter(array_map(static fn (string $id): ?User => $byId[$id] ?? null, $ids)));
    }

    public function emailExists(string $email): bool
    {
        return null !== $this->findOneByEmail($email);
    }

    public function usernameExists(string $username): bool
    {
        return null !== $this->findOneByUsername($username);
    }
}
