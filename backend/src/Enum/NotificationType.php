<?php

declare(strict_types=1);

namespace App\Enum;

/** DATA_MODEL §8b (FB-16). */
enum NotificationType: string
{
    case Follow = 'follow';
    case TrackLike = 'track_like';
    case PlaylistLike = 'playlist_like';
    case PostLike = 'post_like';
    case Comment = 'comment';
}
