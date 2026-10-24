<?php

declare(strict_types=1);

namespace App\Media;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Symfony\Component\Uid\Uuid;

/**
 * Loads an entity with SELECT ... FOR UPDATE and fresh state (the identity
 * map of a long-running worker may hold a stale copy). Call inside a transaction.
 */
final class LockedFind
{
    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    public static function find(EntityManagerInterface $em, string $class, Uuid $id): ?object
    {
        $entity = $em->find($class, $id);
        if (null === $entity) {
            return null;
        }
        try {
            $em->refresh($entity, LockMode::PESSIMISTIC_WRITE);
        } catch (EntityNotFoundException) {
            return null;
        }

        return $entity;
    }
}
