<?php

declare(strict_types=1);

namespace App\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\StringType;

/** PostgreSQL case-insensitive text (extension `citext`), used for email and username. */
final class CitextType extends StringType
{
    public const string NAME = 'citext';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'CITEXT';
    }
}
