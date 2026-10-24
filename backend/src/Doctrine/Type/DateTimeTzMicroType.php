<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeTzType;

/**
 * Introspection maps `timestamptz` back to this type: declaring the same precision as
 * DateTimeTzImmutableMicroType keeps `doctrine:schema:validate` free of false diffs.
 */
final class DateTimeTzMicroType extends DateTimeTzType
{
    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'TIMESTAMP(6) WITH TIME ZONE';
    }
}
