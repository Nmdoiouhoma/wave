<?php

declare(strict_types=1);

namespace App\Listen;

/** DATA_MODEL §11 counting rules, pure (unit-tested). */
final class ListenRules
{
    public const int MIN_MS = 30_000;
    public const int OVERSHOOT_MS = 2_000;

    /** Counted by duration ⇔ (≥ 30 s ∨ ≥ 50 % of the reference) ∧ ≤ reference + 2 s. */
    public static function longEnough(int $listenedMs, int $referenceMs): bool
    {
        return ($listenedMs >= self::MIN_MS || 2 * $listenedMs >= $referenceMs)
            && $listenedMs <= $referenceMs + self::OVERSHOOT_MS;
    }

    /** No second counted listen of the same track by the same user within max(30 s, reference / 2). */
    public static function windowMs(int $referenceMs): int
    {
        return max(self::MIN_MS, intdiv($referenceMs, 2));
    }
}
