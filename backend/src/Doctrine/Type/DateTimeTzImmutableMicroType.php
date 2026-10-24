<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;

/**
 * Keeps microseconds (DBAL's default format drops them): keyset cursors encode
 * timestamps with microseconds (ADR 002) and must match the stored value exactly.
 */
final class DateTimeTzImmutableMicroType extends DateTimeTzImmutableType
{
    private const string FORMAT = 'Y-m-d H:i:s.uO';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TIMESTAMP(6) WITH TIME ZONE';
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            return $value->format(self::FORMAT);
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }
        if (!\is_string($value)) {
            throw InvalidType::new($value, self::class, ['null', 'string', \DateTimeImmutable::class]);
        }
        $parsed = \DateTimeImmutable::createFromFormat(self::FORMAT, $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:sO', $value)
            ?: date_create_immutable($value);
        if (false === $parsed) {
            throw ValueNotConvertible::new($value, self::class);
        }

        return $parsed;
    }
}
