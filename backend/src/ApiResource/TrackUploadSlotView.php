<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\Security\EmailVerifiedVoter;
use App\State\Processor\CreateTrackUploadProcessor;

/** `TrackUploadSlot` schema (ADR 009 step 1). */
#[ApiResource(
    shortName: 'TrackUploadSlot',
    operations: [
        new Post(
            uriTemplate: '/tracks/uploads',
            name: 'createTrackUpload',
            status: 201,
            read: false,
            deserialize: false,
            security: EmailVerifiedVoter::VERIFIED_EXPRESSION,
            securityMessage: EmailVerifiedVoter::DENIAL_MESSAGE,
            processor: CreateTrackUploadProcessor::class,
        ),
    ],
)]
final readonly class TrackUploadSlotView
{
    /** @param array<string, string> $fields */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $uploadId,
        public string $method,
        public string $url,
        public array $fields,
        public int $maxBytes,
        public string $expiresAt,
    ) {
    }
}
