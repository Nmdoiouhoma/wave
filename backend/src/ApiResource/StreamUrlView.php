<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\TrackStreamUrlProvider;

/** `StreamUrl` schema (ADR 001). */
#[ApiResource(
    shortName: 'StreamUrl',
    operations: [
        new Get(
            uriTemplate: '/tracks/{trackId}/stream-url',
            uriVariables: ['trackId'],
            name: 'getTrackStreamUrl',
            provider: TrackStreamUrlProvider::class,
        ),
    ],
)]
final readonly class StreamUrlView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $url,
        public string $expiresAt,
    ) {
    }
}
