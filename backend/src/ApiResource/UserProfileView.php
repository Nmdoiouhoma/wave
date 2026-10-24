<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\UserProfileProvider;

/** `UserProfile` schema. */
#[ApiResource(
    shortName: 'UserProfile',
    operations: [
        new Get(uriTemplate: '/users/{username}', uriVariables: ['username'], name: 'getUserProfile', provider: UserProfileProvider::class),
    ],
)]
final readonly class UserProfileView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $username,
        public string $displayName,
        public ?string $avatarUrl,
        public ?string $bio,
        public int $followersCount,
        public int $followingCount,
        public string $createdAt,
        public bool $isMe,
        public bool $isFollowedByMe,
    ) {
    }
}
