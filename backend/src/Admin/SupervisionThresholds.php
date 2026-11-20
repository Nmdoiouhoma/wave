<?php

declare(strict_types=1);

namespace App\Admin;

use App\Enum\SupervisionLevel;

/**
 * Thresholds of the admin supervision panel (FB-34, W-88, DATA_MODEL §16 note).
 *
 * Every step list holds the lower bounds (inclusive) of `attention`, `alerte`, `critique`, in the
 * unit of the signal's `value`; they are sent as `SupervisionSignal.thresholds` so the client
 * never hard-codes them. Below the first bound → `ok`.
 */
final class SupervisionThresholds
{
    /** Login failure rate (fraction 0–1) over 1 h: ok < 20 %, attention 20–50 %, alerte 50–80 %, critique ≥ 80 %. */
    public const array LOGIN_RATE_STEPS = [0.2, 0.5, 0.8];

    /** Anti-noise floor: below this many attempts (failed + succeeded) the rate is not measured → 0, ok. */
    public const int LOGIN_MIN_ATTEMPTS = 20;

    /** `abuse.rate_limited` count over 1 h: ok 0, attention 1–9, alerte 10–49, critique ≥ 50. */
    public const array ABUSE_STEPS = [1, 10, 50];

    /** `upload.rejected` count over 1 h: same steps as abuse. */
    public const array UPLOAD_STEPS = [1, 10, 50];

    /** Sliding window of the logins / abuse / uploads signals, in seconds. */
    public const int WINDOW_SECONDS = 3600;

    /** Whether the login rate is measured (enough attempts in the window). */
    public static function loginMeasured(int $failed, int $succeeded): bool
    {
        return $failed + $succeeded >= self::LOGIN_MIN_ATTEMPTS;
    }

    /** Failure rate 0–1, forced to 0 below the minimum attempts. */
    public static function loginRate(int $failed, int $succeeded): float
    {
        return self::loginMeasured($failed, $succeeded) ? $failed / ($failed + $succeeded) : 0.0;
    }

    public static function loginLevel(int $failed, int $succeeded): SupervisionLevel
    {
        return self::step(self::loginRate($failed, $succeeded), self::LOGIN_RATE_STEPS);
    }

    public static function abuseLevel(int $count): SupervisionLevel
    {
        return self::step($count, self::ABUSE_STEPS);
    }

    public static function uploadLevel(int $count): SupervisionLevel
    {
        return self::step($count, self::UPLOAD_STEPS);
    }

    /**
     * @param list<int|float> $steps lower bounds of attention, alerte, critique
     */
    public static function step(int|float $value, array $steps): SupervisionLevel
    {
        return match (true) {
            $value >= $steps[2] => SupervisionLevel::Critique,
            $value >= $steps[1] => SupervisionLevel::Alerte,
            $value >= $steps[0] => SupervisionLevel::Attention,
            default => SupervisionLevel::Ok,
        };
    }
}
