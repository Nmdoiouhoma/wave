<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\State\Processor\AcknowledgeAlertProcessor;
use App\State\Provider\SecurityAlertListProvider;

/** `SecurityAlert` schema (DATA_MODEL §16) and its ROLE_ADMIN routes (ADR 013). */
#[ApiResource(
    shortName: 'SecurityAlert',
    operations: [
        new Get(
            uriTemplate: '/admin/alerts',
            name: 'listSecurityAlerts',
            output: SecurityAlertPage::class,
            security: "is_granted('ROLE_ADMIN')",
            provider: SecurityAlertListProvider::class,
        ),
        new Post(
            uriTemplate: '/admin/alerts/{alertId}/acknowledgement',
            uriVariables: ['alertId'],
            name: 'acknowledgeSecurityAlert',
            status: 200,
            read: false,
            deserialize: false,
            security: "is_granted('ROLE_ADMIN')",
            processor: AcknowledgeAlertProcessor::class,
        ),
    ],
)]
final readonly class SecurityAlertView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $rule,
        public string $severity,
        public ?UserSummary $subjectUser,
        public ?string $subjectIpHash,
        public ?SecurityAlertTargetView $subjectTarget,
        public int $hitCount,
        public string $firstSeenAt,
        public string $lastSeenAt,
        public string $status,
        public ?UserSummary $acknowledgedBy,
        public ?string $acknowledgedAt,
        public ?string $note,
    ) {
    }
}
