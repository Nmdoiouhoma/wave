<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Security\EmailVerifiedVoter;
use App\State\Processor\CreateReportProcessor;

/** `ReportReceipt` schema: `POST /reports` (201). Never reveals whether auto-hide happened. */
#[ApiResource(
    shortName: 'Report',
    operations: [
        new Post(
            uriTemplate: '/reports',
            name: 'createReport',
            status: 201,
            read: false,
            deserialize: false,
            security: EmailVerifiedVoter::VERIFIED_EXPRESSION,
            securityMessage: EmailVerifiedVoter::DENIAL_MESSAGE,
            processor: CreateReportProcessor::class,
        ),
    ],
)]
final readonly class ReportReceipt
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $status,
        public string $createdAt,
    ) {
    }
}
