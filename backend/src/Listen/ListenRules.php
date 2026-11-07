<?php

declare(strict_types=1);

namespace App\Listen;

/** DATA_MODEL §11 counting rules, pure (unit-tested). */
final class ListenRules
{
    public const int MIN_MS = 30_000;
    public const int OVERSHOOT_MS = 2_000;
    public const int GRANT_SLACK_MS = 1_800_000;
    /** Counted listens of one (user, track) over a rolling 24 h. */
    public const int DAILY_CAP = 20;

    /** Counted by duration ⇔ (≥ 30 s ∨ ≥ 50 % of the reference) ∧ ≤ reference + 2 s. */
    public static function longEnough(int $listenedMs, int $referenceMs): bool
    {
        return ($listenedMs >= self::MIN_MS || 2 * $listenedMs >= $referenceMs)
            && $listenedMs <= $referenceMs + self::OVERSHOOT_MS;
    }

    /** Anti-replay (ADR 010): no second counted listen of the same (user, track) within max(30 s, reference). */
    public static function windowMs(int $referenceMs): int
    {
        return max(self::MIN_MS, $referenceMs);
    }

    /** A grant is fresh while its age is at most reference + 30 min (ADR 010). */
    public static function grantFresh(int $grantAgeMs, int $referenceMs): bool
    {
        return $grantAgeMs >= 0 && $grantAgeMs <= $referenceMs + self::GRANT_SLACK_MS;
    }

    /** One cannot have played more than the time elapsed since the stream URL was issued (+ 2 s). */
    public static function elapsedOk(int $listenedMs, int $elapsedMs): bool
    {
        return $listenedMs <= $elapsedMs + self::OVERSHOOT_MS;
    }
}
