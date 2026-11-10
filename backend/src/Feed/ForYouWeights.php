<?php

declare(strict_types=1);

namespace App\Feed;

/** ADR 011 score weights and windows: the only place they live. */
final class ForYouWeights
{
    public const float FOLLOW = 0.35;
    public const float GENRE = 0.25;
    public const float POPULAR_RECENT = 0.25;
    public const float FRESH = 0.10;
    public const float JITTER = 0.05;
    /** Freshness half-life, hours: R = 0.5 ^ (age_hours / HALF_LIFE_H). */
    public const int HALF_LIFE_H = 72;
    /** Genre affinity window before now₀, days. */
    public const int GENRE_WINDOW_D = 90;
    /** P = least(1, ln(1 + 3·likes + 4·comments + views) / ln(1 + POPULARITY_CAP)). */
    public const int POPULARITY_CAP = 1000;

    private function __construct()
    {
    }
}
