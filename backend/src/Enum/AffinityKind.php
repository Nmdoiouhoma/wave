<?php

declare(strict_types=1);

namespace App\Enum;

enum AffinityKind: string
{
    case Genre = 'genre';
    case Artist = 'artist';
}
