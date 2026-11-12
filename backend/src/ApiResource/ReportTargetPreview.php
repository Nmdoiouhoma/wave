<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `ReportTarget.preview`: label truncated to 140 characters; owner = author/owner (the user itself for a user target). */
final readonly class ReportTargetPreview
{
    public function __construct(
        public string $label,
        public ?string $imageUrl,
        public UserSummary $owner,
    ) {
    }
}
