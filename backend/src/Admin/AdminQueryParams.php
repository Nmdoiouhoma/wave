<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\User;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/** Query-string and path helpers of the back-office routes: a bad filter is 400 `malformed_request`. */
final readonly class AdminQueryParams
{
    public function __construct(private UserRepository $users)
    {
    }

    /** `/admin/users/{userId}`: a malformed id is the same 404 as an unknown one. */
    public function user(mixed $id): User
    {
        $user = \is_string($id) && Uuid::isValid($id) ? $this->users->find(Uuid::fromString($id)) : null;

        return $user ?? throw ApiProblemException::notFound();
    }

    /** @param list<string> $allowed */
    public static function choice(?Request $request, string $name, array $allowed): ?string
    {
        $value = self::string($request, $name);
        if (null !== $value && !\in_array($value, $allowed, true)) {
            throw new ApiProblemException(ProblemCode::MalformedRequest, $name.' is not a valid value.');
        }

        return $value;
    }

    /** Trimmed text between $min and $max characters, or null when absent. */
    public static function text(?Request $request, string $name, int $min, int $max): ?string
    {
        $value = self::string($request, $name);
        if (null === $value) {
            return null;
        }
        $value = trim($value);
        $length = mb_strlen($value);
        if ($length < $min || $length > $max) {
            throw new ApiProblemException(ProblemCode::MalformedRequest, \sprintf('%s must be %d to %d characters long.', $name, $min, $max));
        }

        return $value;
    }

    public static function uuid(?Request $request, string $name): ?Uuid
    {
        $value = self::string($request, $name);
        if (null === $value) {
            return null;
        }
        if (!Uuid::isValid($value)) {
            throw new ApiProblemException(ProblemCode::MalformedRequest, $name.' must be a uuid.');
        }

        return Uuid::fromString($value);
    }

    private static function string(?Request $request, string $name): ?string
    {
        if (null === $request || !$request->query->has($name)) {
            return null;
        }
        $raw = $request->query->all()[$name];
        if (!\is_string($raw) || !mb_check_encoding($raw, 'UTF-8') || str_contains($raw, "\0")) {
            throw new ApiProblemException(ProblemCode::MalformedRequest, $name.' is not a valid value.');
        }

        return $raw;
    }
}
