<?php

declare(strict_types=1);

namespace App\Enum;

enum ReportTargetType: string
{
    case Track = 'track';
    case Post = 'post';
    case Comment = 'comment';
    case Playlist = 'playlist';
    case Artist = 'artist';
    case User = 'user';
}
