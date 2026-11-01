<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\State\Processor\PlaylistItemProcessor;
use App\State\Provider\PlaylistItemsProvider;

/** `PlaylistItem` schema: `track` is null when the track is not visible to the caller. */
#[ApiResource(
    shortName: 'PlaylistItem',
    operations: [
        new Get(uriTemplate: '/playlists/{playlistId}/items', uriVariables: ['playlistId'], name: 'listPlaylistItems', output: PlaylistItemPage::class, provider: PlaylistItemsProvider::class),
        new Post(uriTemplate: '/playlists/{playlistId}/items', uriVariables: ['playlistId'], name: 'addPlaylistItem', status: 201, read: false, deserialize: false, processor: PlaylistItemProcessor::class),
        new Delete(uriTemplate: '/playlists/{playlistId}/items/{trackId}', uriVariables: ['playlistId', 'trackId'], name: 'removePlaylistItem', read: false, deserialize: false, output: false, processor: PlaylistItemProcessor::class),
        new Put(uriTemplate: '/playlists/{playlistId}/order', uriVariables: ['playlistId'], name: 'reorderPlaylist', status: 204, read: false, deserialize: false, output: false, processor: PlaylistItemProcessor::class),
    ],
)]
final readonly class PlaylistItemView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $trackId,
        public int $position,
        public string $addedAt,
        public ?TrackView $track,
    ) {
    }
}
