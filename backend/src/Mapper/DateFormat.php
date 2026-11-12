<?php

declare(strict_types=1);

namespace App\Mapper;

/** Wire format of dates: ISO 8601 UTC with `Z`, second precision (CONVENTIONS §4). */
final class DateFormat
{
    public static function format(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * Microsecond precision (`2026-11-12T10:19:20.006574+00:00`), the full precision of Postgres
     * `timestamptz`: notification `updatedAt` only, sent back unchanged as the `upTo` mark of
     * `POST /me/notifications/read` and compared exactly.
     */
    public static function formatMicros(\DateTimeInterface $date): string
    {
        return \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }

    public static function nullable(?\DateTimeInterface $date): ?string
    {
        return null === $date ? null : self::format($date);
    }
}
