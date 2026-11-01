<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Put;
use App\State\Processor\FollowProcessor;

/** `FollowState` schema; follow/unfollow are idempotent (DATA_MODEL §8). */
#[ApiResource(
    shortName: 'FollowState',
    operations: [
        new Put(uriTemplate: '/users/{username}/follow', uriVariables: ['username'], name: 'followUser', read: false, deserialize: false, processor: FollowProcessor::class),
        new Delete(uriTemplate: '/users/{username}/follow', uriVariables: ['username'], name: 'unfollowUser', status: 200, read: false, deserialize: false, processor: FollowProcessor::class),
    ],
)]
final readonly class FollowStateView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public bool $following,
        public int $followersCount,
    ) {
    }
}
