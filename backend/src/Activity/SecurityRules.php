<?php

declare(strict_types=1);

namespace App\Activity;

use App\Enum\ActivityType;
use App\Enum\AlertRule;
use App\Enum\AlertSeverity;

/**
 * DATA_MODEL §16 (ADR 013): the fixed alert rules. Thresholds are code constants on purpose
 * (no rule editor in v1); windows slide on `activity_event.created_at`, ending at the event
 * being evaluated.
 */
final class SecurityRules
{
    /** `login_failures`: ≥ 10 `auth.login_failed` for one account in 15 min… */
    public const int LOGIN_FAILURES_PER_ACCOUNT = 10;
    /** …or ≥ 30 from one IP hash in 15 min. */
    public const int LOGIN_FAILURES_PER_IP = 30;
    public const string LOGIN_FAILURES_WINDOW = 'PT15M';

    /** `mass_registration`: ≥ 3 `account.registered` from one IP hash in 1 h. */
    public const int MASS_REGISTRATION = 3;
    public const string MASS_REGISTRATION_WINDOW = 'PT1H';

    /** `report_spree`: ≥ 15 `report.created` by one user in 1 h. */
    public const int REPORT_SPREE = 15;
    public const string REPORT_SPREE_WINDOW = 'PT1H';

    /** `upload_abuse`: ≥ 5 `upload.rejected` by one user in 1 h. */
    public const int UPLOAD_ABUSE = 5;
    public const string UPLOAD_ABUSE_WINDOW = 'PT1H';

    /** `rate_limit_hammering`: ≥ 10 `abuse.rate_limited` for one user (or IP hash when anonymous) in 10 min. */
    public const int RATE_LIMIT_HAMMERING = 10;
    public const string RATE_LIMIT_HAMMERING_WINDOW = 'PT10M';

    // `token_reuse` (any `auth.token_reused`), `report_burst` (any `report.auto_hidden`) and
    // `privilege_granted` (any `admin.admin_granted`) fire on every event: threshold 1.

    /** Journal types that dispatch `EvaluateSecurityRules`. */
    public static function watches(ActivityType $type): bool
    {
        return null !== self::ruleFor($type);
    }

    public static function ruleFor(ActivityType $type): ?AlertRule
    {
        return match ($type) {
            ActivityType::LoginFailed => AlertRule::LoginFailures,
            ActivityType::TokenReused => AlertRule::TokenReuse,
            ActivityType::Registered => AlertRule::MassRegistration,
            ActivityType::ReportAutoHidden => AlertRule::ReportBurst,
            ActivityType::ReportCreated => AlertRule::ReportSpree,
            ActivityType::UploadRejected => AlertRule::UploadAbuse,
            ActivityType::RateLimited => AlertRule::RateLimitHammering,
            ActivityType::AdminGranted => AlertRule::PrivilegeGranted,
            default => null,
        };
    }

    public static function severity(AlertRule $rule): AlertSeverity
    {
        return match ($rule) {
            AlertRule::TokenReuse, AlertRule::PrivilegeGranted => AlertSeverity::High,
            AlertRule::LoginFailures, AlertRule::MassRegistration, AlertRule::ReportSpree, AlertRule::UploadAbuse => AlertSeverity::Medium,
            AlertRule::ReportBurst, AlertRule::RateLimitHammering => AlertSeverity::Low,
        };
    }

    /** Sort rank of `GET /admin/alerts` (highest first). */
    public static function severityRank(AlertSeverity $severity): int
    {
        return match ($severity) {
            AlertSeverity::High => 3,
            AlertSeverity::Medium => 2,
            AlertSeverity::Low => 1,
        };
    }
}
