<?php

declare(strict_types=1);

namespace App\Activity;

use App\Entity\User;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** Shared checks of the W-72 admin routes: ROLE_ADMIN guard and query filters (400 on a bad value). */
final class AdminQuery
{
    /** ADR 013 §8: an admin-only route answers 403 to a moderator before any lookup. */
    public static function requireAdmin(User $user): User
    {
        if (!$user->isAdmin()) {
            throw ApiProblemException::forbidden();
        }

        return $user;
    }

    /**
     * @template E of \BackedEnum
     *
     * @param class-string<E> $enum
     *
     * @return E|null
     */
    public static function enum(?Request $request, string $name, string $enum): ?\BackedEnum
    {
        $raw = self::raw($request, $name);
        if (null === $raw) {
            return null;
        }
        $value = \is_string($raw) ? $enum::tryFrom($raw) : null;

        return $value ?? throw new ApiProblemException(ProblemCode::MalformedRequest, $name.' is not a valid value.');
    }

    public static function uuid(?Request $request, string $name): ?Uuid
    {
        $raw = self::raw($request, $name);
        if (null === $raw) {
            return null;
        }
        if (!\is_string($raw) || !Uuid::isValid($raw)) {
            throw new ApiProblemException(ProblemCode::MalformedRequest, $name.' is not a valid id.');
        }

        return Uuid::fromString($raw);
    }

    /**
     * Cursor of an `id` DESC keyset: [id].
     *
     * @param list<string|int|float|bool|null>|null $keys
     */
    public static function afterId(?array $keys): ?Uuid
    {
        if (null === $keys) {
            return null;
        }
        if (1 !== \count($keys) || !\is_string($keys[0]) || !Uuid::isValid($keys[0])) {
            throw \App\Pagination\CursorCodec::invalid();
        }

        return Uuid::fromString($keys[0]);
    }

    private static function raw(?Request $request, string $name): mixed
    {
        if (null === $request || !$request->query->has($name)) {
            return null;
        }

        return $request->query->all()[$name];
    }
}
