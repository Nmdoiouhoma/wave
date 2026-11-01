<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\State\Processor\PlaylistProcessor;
use App\State\Provider\PlaylistListProvider;
use App\State\Provider\PlaylistProvider;

/** `Playlist` schema (DATA_MODEL §7). */
#[ApiResource(
    shortName: 'Playlist',
    operations: [
        new Post(uriTemplate: '/playlists', name: 'createPlaylist', status: 201, read: false, deserialize: false, processor: PlaylistProcessor::class),
        new Get(uriTemplate: '/playlists/{playlistId}', uriVariables: ['playlistId'], name: 'getPlaylist', provider: PlaylistProvider::class),
        new Patch(uriTemplate: '/playlists/{playlistId}', uriVariables: ['playlistId'], name: 'updatePlaylist', read: false, deserialize: false, processor: PlaylistProcessor::class),
        new Delete(uriTemplate: '/playlists/{playlistId}', uriVariables: ['playlistId'], name: 'deletePlaylist', read: false, deserialize: false, output: false, processor: PlaylistProcessor::class),
        new Get(uriTemplate: '/users/{username}/playlists', uriVariables: ['username'], name: 'listUserPlaylists', output: PlaylistPage::class, provider: PlaylistListProvider::class),
        new Get(uriTemplate: '/me/likes/playlists', name: 'listMyLikedPlaylists', output: PlaylistPage::class, provider: PlaylistListProvider::class),
    ],
)]
final readonly class PlaylistView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $name,
        public ?string $description,
        public bool $isPublic,
        public UserSummary $owner,
        public ?string $coverUrl,
        public int $itemCount,
        public int $likeCount,
        public bool $likedByMe,
        public string $moderation,
        public bool $isOwner,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
