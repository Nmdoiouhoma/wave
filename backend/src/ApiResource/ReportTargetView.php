<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\State\Processor\ModerationDecisionProcessor;
use App\State\Provider\ReportTargetListProvider;
use App\State\Provider\TargetReportsProvider;

/**
 * `ReportTarget` schema and the `/admin` routes (DATA_MODEL §13). ROLE_MODERATOR is enforced
 * twice: `access_control` on `^/api/v1/admin` (403 before any lookup) and `security` here.
 */
#[ApiResource(
    shortName: 'ReportTarget',
    operations: [
        new Get(
            uriTemplate: '/admin/report-targets',
            name: 'listReportTargets',
            output: ReportTargetPage::class,
            security: "is_granted('ROLE_MODERATOR')",
            provider: ReportTargetListProvider::class,
        ),
        new Get(
            uriTemplate: '/admin/report-targets/{targetType}/{targetId}/reports',
            uriVariables: ['targetType', 'targetId'],
            name: 'listReportsForTarget',
            output: ReportPage::class,
            security: "is_granted('ROLE_MODERATOR')",
            provider: TargetReportsProvider::class,
        ),
        new Post(
            uriTemplate: '/admin/report-targets/{targetType}/{targetId}/decision',
            uriVariables: ['targetType', 'targetId'],
            name: 'decideReportTarget',
            status: 200,
            read: false,
            deserialize: false,
            security: "is_granted('ROLE_MODERATOR')",
            processor: ModerationDecisionProcessor::class,
        ),
    ],
)]
final readonly class ReportTargetView
{
    /** @param object $reasons reason => count (a JSON object, `{}` when empty) */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $targetType,
        #[ApiProperty(identifier: true)]
        public string $targetId,
        public ?ReportTargetPreview $preview,
        public ?string $moderation,
        public ?bool $suspended,
        public int $openReportCount,
        public object $reasons,
        public string $firstReportedAt,
        public string $lastReportedAt,
    ) {
    }
}
