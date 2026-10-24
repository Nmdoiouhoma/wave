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

    public static function nullable(?\DateTimeInterface $date): ?string
    {
        return null === $date ? null : self::format($date);
    }
}
