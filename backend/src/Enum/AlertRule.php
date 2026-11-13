<?php

declare(strict_types=1);

namespace App\Enum;

/** DATA_MODEL §16. Thresholds and severities: SecurityRules (W-72). */
enum AlertRule: string
{
    case LoginFailures = 'login_failures';
    case TokenReuse = 'token_reuse';
    case MassRegistration = 'mass_registration';
    case ReportBurst = 'report_burst';
    case ReportSpree = 'report_spree';
    case UploadAbuse = 'upload_abuse';
    case RateLimitHammering = 'rate_limit_hammering';
    case PrivilegeGranted = 'privilege_granted';
}
