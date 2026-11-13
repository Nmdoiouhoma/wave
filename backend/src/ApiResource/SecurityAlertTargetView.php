<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `SecurityAlert.subjectTarget` (embedded, `report_burst` only). */
final readonly class SecurityAlertTargetView
{
    public function __construct(
        public string $targetType,
        public string $targetId,
    ) {
    }
}
