<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Put;
use App\State\Processor\LikeProcessor;

/** `LikeState` schema; like/unlike are idempotent (DATA_MODEL §8). */
#[ApiResource(
    shortName: 'LikeState',
    operations: [
        new Put(uriTemplate: '/tracks/{trackId}/like', uriVariables: ['trackId'], name: 'likeTrack', read: false, deserialize: false, processor: LikeProcessor::class),
        new Delete(uriTemplate: '/tracks/{trackId}/like', uriVariables: ['trackId'], name: 'unlikeTrack', status: 200, read: false, deserialize: false, processor: LikeProcessor::class),
        new Put(uriTemplate: '/playlists/{playlistId}/like', uriVariables: ['playlistId'], name: 'likePlaylist', read: false, deserialize: false, processor: LikeProcessor::class),
        new Delete(uriTemplate: '/playlists/{playlistId}/like', uriVariables: ['playlistId'], name: 'unlikePlaylist', status: 200, read: false, deserialize: false, processor: LikeProcessor::class),
    ],
)]
final readonly class LikeStateView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public bool $liked,
        public int $likeCount,
    ) {
    }
}
