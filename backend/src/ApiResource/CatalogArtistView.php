<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Catalog\CatalogAttribution;
use App\Catalog\CatalogTrack;
use App\State\Provider\CatalogArtistProvider;

/**
 * `CatalogArtist` schema (ADR 014): an artist with up to 10 top tracks. Metadata cached 24 h,
 * top tracks 10 min. On provider failure: stale metadata with `topTracks: []`, else 503
 * `catalog_unavailable` + `Retry-After`. Unknown id → 404. Flag off → 404.
 */
#[ApiResource(
    shortName: 'CatalogArtist',
    operations: [
        new Get(
            uriTemplate: '/catalog/artists/{externalId}',
            uriVariables: ['externalId'],
            requirements: ['externalId' => 'dz:[0-9]{1,15}'],
            name: 'getCatalogArtist',
            provider: CatalogArtistProvider::class,
        ),
    ],
)]
final readonly class CatalogArtistView
{
    /** @param list<CatalogTrack> $topTracks */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $name,
        public ?string $pictureUrl,
        public ?int $fanCount,
        public string $deezerUrl,
        public array $topTracks,
        public CatalogAttribution $attribution,
    ) {
    }
}
