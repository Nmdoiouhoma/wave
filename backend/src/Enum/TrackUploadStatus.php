<?php

declare(strict_types=1);

namespace App\Enum;

enum TrackUploadStatus: string
{
    case Pending = 'pending';
    case Consumed = 'consumed';
    case Expired = 'expired';
}
