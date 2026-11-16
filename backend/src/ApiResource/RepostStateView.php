<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Put;
use App\Security\EmailVerifiedVoter;
use App\State\Processor\RepostProcessor;

/** `RepostState` schema; repost/undo are idempotent (DATA_MODEL §9b, ADR 015). */
#[ApiResource(
    shortName: 'RepostState',
    operations: [
        new Put(
            uriTemplate: '/posts/{postId}/repost',
            uriVariables: ['postId'],
            name: 'repostPost',
            read: false,
            deserialize: false,
            security: EmailVerifiedVoter::VERIFIED_EXPRESSION,
            securityMessage: EmailVerifiedVoter::DENIAL_MESSAGE,
            processor: RepostProcessor::class,
        ),
        new Delete(uriTemplate: '/posts/{postId}/repost', uriVariables: ['postId'], name: 'unrepostPost', status: 200, read: false, deserialize: false, processor: RepostProcessor::class),
    ],
)]
final readonly class RepostStateView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public bool $reposted,
        public int $repostCount,
    ) {
    }
}
