<?php

declare(strict_types=1);

namespace App\Search;

enum SearchType: string
{
    case Tracks = 'tracks';
    case Artists = 'artists';
    case Users = 'users';
    case Playlists = 'playlists';
    case Hashtags = 'hashtags';
}
