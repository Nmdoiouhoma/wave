<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Security\EmailVerifiedVoter;
use App\State\Processor\ChangePasswordProcessor;
use App\State\Processor\MyAvatarProcessor;
use App\State\Processor\ResendEmailVerificationProcessor;
use App\State\Processor\UpdateMeProcessor;
use App\State\Provider\MeProvider;

/** `Me` schema: UserProfile + email, emailVerified, roles. */
#[ApiResource(
    shortName: 'Me',
    operations: [
        new Get(uriTemplate: '/me', name: 'getMe', provider: MeProvider::class),
        new Patch(uriTemplate: '/me', name: 'updateMe', read: false, deserialize: false, processor: UpdateMeProcessor::class),
        new Post(
            uriTemplate: '/me/avatar',
            name: 'uploadMyAvatar',
            status: 202,
            read: false,
            deserialize: false,
            inputFormats: ['multipart' => ['multipart/form-data']],
            security: EmailVerifiedVoter::VERIFIED_EXPRESSION,
            securityMessage: EmailVerifiedVoter::DENIAL_MESSAGE,
            processor: MyAvatarProcessor::class,
        ),
        new Delete(uriTemplate: '/me/avatar', name: 'deleteMyAvatar', read: false, deserialize: false, output: false, processor: MyAvatarProcessor::class),
        new Post(uriTemplate: '/me/password', name: 'changePassword', status: 200, read: false, deserialize: false, processor: ChangePasswordProcessor::class),
        new Post(uriTemplate: '/me/email-verification', name: 'resendEmailVerification', status: 202, read: false, deserialize: false, output: false, processor: ResendEmailVerificationProcessor::class),
    ],
)]
final readonly class MeView
{
    /** @param list<string> $roles */
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
        public string $email,
        public bool $emailVerified,
        public array $roles,
    ) {
    }
}
