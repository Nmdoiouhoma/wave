<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Security\EmailVerifiedVoter;
use App\State\Processor\ArtistAvatarProcessor;
use App\State\Processor\ArtistProcessor;
use App\State\Provider\ArtistProvider;
use App\State\Provider\UserArtistsProvider;

/** `Artist` schema (D6, ADR 004). */
#[ApiResource(
    shortName: 'Artist',
    operations: [
        new Post(
            uriTemplate: '/artists',
            name: 'createArtist',
            read: false,
            deserialize: false,
            security: EmailVerifiedVoter::VERIFIED_EXPRESSION,
            securityMessage: EmailVerifiedVoter::DENIAL_MESSAGE,
            processor: ArtistProcessor::class,
        ),
        new Get(uriTemplate: '/artists/{slug}', uriVariables: ['slug'], name: 'getArtist', provider: ArtistProvider::class),
        new Patch(
            uriTemplate: '/artists/{slug}',
            uriVariables: ['slug'],
            name: 'updateArtist',
            read: false,
            deserialize: false,
            security: EmailVerifiedVoter::VERIFIED_EXPRESSION,
            securityMessage: EmailVerifiedVoter::DENIAL_MESSAGE,
            processor: ArtistProcessor::class,
        ),
        new Delete(uriTemplate: '/artists/{slug}', uriVariables: ['slug'], name: 'deleteArtist', read: false, deserialize: false, output: false, processor: ArtistProcessor::class),
        new Post(
            uriTemplate: '/artists/{slug}/avatar',
            uriVariables: ['slug'],
            name: 'uploadArtistAvatar',
            status: 202,
            read: false,
            deserialize: false,
            inputFormats: ['multipart' => ['multipart/form-data']],
            security: EmailVerifiedVoter::VERIFIED_EXPRESSION,
            securityMessage: EmailVerifiedVoter::DENIAL_MESSAGE,
            processor: ArtistAvatarProcessor::class,
        ),
        new Delete(uriTemplate: '/artists/{slug}/avatar', uriVariables: ['slug'], name: 'deleteArtistAvatar', read: false, deserialize: false, output: false, processor: ArtistAvatarProcessor::class),
        new GetCollection(uriTemplate: '/users/{username}/artists', uriVariables: ['username'], name: 'listUserArtists', provider: UserArtistsProvider::class),
    ],
)]
final readonly class ArtistView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $slug,
        public string $name,
        public ?string $avatarUrl,
        public ?string $bio,
        public int $trackCount,
        public UserSummary $owner,
        public string $moderation,
        public bool $isOwner,
        public string $createdAt,
    ) {
    }
}
