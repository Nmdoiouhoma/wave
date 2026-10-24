<?php

declare(strict_types=1);

namespace App\Pagination;

use Symfony\Component\Uid\Uuid;

/** Helpers for the common `(created_at DESC, id DESC)` keyset. */
final class Keyset
{
    public const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.u\Z';

    /**
     * @param list<string|int|float|bool|null> $keys
     *
     * @return array{\DateTimeImmutable, Uuid}
     */
    public static function timestampAndId(array $keys): array
    {
        if (2 !== \count($keys) || !\is_string($keys[0]) || !\is_string($keys[1]) || !Uuid::isValid($keys[1])) {
            throw CursorCodec::invalid();
        }
        $at = \DateTimeImmutable::createFromFormat(self::TIMESTAMP_FORMAT, $keys[0], new \DateTimeZone('UTC'));
        if (false === $at) {
            throw CursorCodec::invalid();
        }

        return [$at, Uuid::fromString($keys[1])];
    }

    /** @return list<string> */
    public static function fromTimestampAndId(\DateTimeImmutable $at, Uuid $id): array
    {
        return [$at->setTimezone(new \DateTimeZone('UTC'))->format(self::TIMESTAMP_FORMAT), $id->toRfc4122()];
    }
}
