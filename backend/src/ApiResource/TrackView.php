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
use App\State\Processor\TrackCoverProcessor;
use App\State\Processor\TrackProcessor;
use App\State\Processor\UploadTrackProcessor;
use App\State\Provider\TrackListProvider;
use App\State\Provider\TrackProvider;

/** `Track` schema. Visibility: DATA_MODEL §6 (others see public-visible tracks only, else 404). */
#[ApiResource(
    shortName: 'Track',
    operations: [
        new Post(
            uriTemplate: '/tracks',
            name: 'uploadTrack',
            status: 202,
            read: false,
            deserialize: false,
            inputFormats: ['multipart' => ['multipart/form-data']],
            security: EmailVerifiedVoter::VERIFIED_EXPRESSION,
            securityMessage: EmailVerifiedVoter::DENIAL_MESSAGE,
            processor: UploadTrackProcessor::class,
        ),
        new Get(uriTemplate: '/tracks/{trackId}', uriVariables: ['trackId'], name: 'getTrack', provider: TrackProvider::class),
        new Patch(uriTemplate: '/tracks/{trackId}', uriVariables: ['trackId'], name: 'updateTrack', read: false, deserialize: false, processor: TrackProcessor::class),
        new Delete(uriTemplate: '/tracks/{trackId}', uriVariables: ['trackId'], name: 'deleteTrack', read: false, deserialize: false, output: false, processor: TrackProcessor::class),
        new Post(
            uriTemplate: '/tracks/{trackId}/cover',
            uriVariables: ['trackId'],
            name: 'uploadTrackCover',
            status: 202,
            read: false,
            deserialize: false,
            inputFormats: ['multipart' => ['multipart/form-data']],
            security: EmailVerifiedVoter::VERIFIED_EXPRESSION,
            securityMessage: EmailVerifiedVoter::DENIAL_MESSAGE,
            processor: TrackCoverProcessor::class,
        ),
        new Delete(uriTemplate: '/tracks/{trackId}/cover', uriVariables: ['trackId'], name: 'deleteTrackCover', read: false, deserialize: false, output: false, processor: TrackCoverProcessor::class),
        new Get(uriTemplate: '/users/{username}/tracks', uriVariables: ['username'], name: 'listUserTracks', output: TrackPage::class, provider: TrackListProvider::class),
        new Get(uriTemplate: '/artists/{slug}/tracks', uriVariables: ['slug'], name: 'listArtistTracks', output: TrackPage::class, provider: TrackListProvider::class),
    ],
)]
final readonly class TrackView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $title,
        public ArtistSummary $artist,
        public UserSummary $owner,
        public GenreView $genre,
        public ?string $coverUrl,
        public ?int $durationMs,
        public string $status,
        public ?string $failureReason,
        public string $visibility,
        public bool $allowClips,
        public string $moderation,
        public int $playCount,
        public int $likeCount,
        public bool $likedByMe,
        public bool $isOwner,
        public bool $canClip,
        public string $createdAt,
    ) {
    }
}
