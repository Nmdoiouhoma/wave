<?php

declare(strict_types=1);

namespace App\Enum;

/** Track audience (FB-18, ADR 012, DATA_MODEL §6). `Private` = owner only. */
enum Visibility: string
{
    case Public = 'public';
    case Followers = 'followers';
    case CloseFriends = 'close_friends';
    case Private = 'private';
}
