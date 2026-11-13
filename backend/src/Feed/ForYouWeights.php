<?php

declare(strict_types=1);

namespace App\Feed;

/**
 * ADR 005 weights, windows and half-lives: the only place they live (read by SqlFeedRanker and
 * the Recommendation recomputers). Changing a value changes the ranking, nothing else.
 */
final class ForYouWeights
{
    // ── Score of a candidate post: 0.30·G + 0.20·A + 0.20·F + 0.30·T·R + 0.05·J ──
    public const float GENRE = 0.30;
    public const float ARTIST = 0.20;
    public const float FOLLOW = 0.20;
    public const float TRENDING_FRESH = 0.30;
    public const float JITTER = 0.05;

    /** F when a followed user (not the author) liked the post in the last FOLLOWED_LIKE_WINDOW_D days. */
    public const float FOLLOWED_LIKE = 0.5;
    public const int FOLLOWED_LIKE_WINDOW_D = 7;

    /** Candidates are posts created in the last CANDIDATE_WINDOW_D days. */
    public const int CANDIDATE_WINDOW_D = 30;

    /** Freshness R = 0.5 ^ (age_hours / FRESH_HALF_LIFE_H). */
    public const int FRESH_HALF_LIFE_H = 72;

    /**
     * Cold-start floor (deviation from the literal ADR 005 `T·R`, which would drop freshness
     * when T = 0): the trending-and-fresh term is R · (TREND_FLOOR + (1 − TREND_FLOOR) · T).
     */
    public const float TREND_FLOOR = 0.25;

    // ── user_affinity (RecomputeAffinity) ──
    public const int AFFINITY_WINDOW_D = 90;
    public const int AFFINITY_HALF_LIFE_D = 30;
    public const int AFFINITY_TOP = 20;
    public const float AFFINITY_TRACK_LIKE = 3.0;
    public const float AFFINITY_PLAYER_LISTEN = 1.0;
    public const float AFFINITY_POST_LIKE = 2.0;
    public const float AFFINITY_FEED_LISTEN = 0.5;
    public const float AFFINITY_COMPLETED_VIEW = 0.5;

    // ── trending_score (RecomputeTrending) ──
    public const int TRACK_TRENDING_WINDOW_D = 7;
    public const int TRACK_TRENDING_HALF_LIFE_H = 48;
    public const float TRACK_LISTEN = 1.0;
    public const float TRACK_LIKE = 3.0;
    public const int POST_TRENDING_WINDOW_D = 3;
    public const int POST_TRENDING_HALF_LIFE_H = 24;
    public const float POST_VIEW = 1.0;
    public const float POST_COMPLETED_VIEW = 1.0;
    public const float POST_LIKE = 3.0;
    public const float POST_COMMENT = 4.0;

    // ── Reposts (ADR 015 §6): wired in W-80 (the repost table does not exist yet), unused until then ──
    public const float REPOST_FOLLOW = 0.75;
    public const float REPOST_TRENDING = 4.0;
    public const float REPOST_AFFINITY = 3.0;

    private function __construct()
    {
    }
}
