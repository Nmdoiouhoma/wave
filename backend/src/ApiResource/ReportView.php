<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `Report` schema (moderators only). */
final readonly class ReportView
{
    public function __construct(
        public string $id,
        public UserSummary $reporter,
        public string $targetType,
        public string $targetId,
        public string $reason,
        public ?string $details,
        public string $status,
        public ?UserSummary $handledBy,
        public ?string $handledAt,
        public ?string $decisionNote,
        public string $createdAt,
    ) {
    }
}
