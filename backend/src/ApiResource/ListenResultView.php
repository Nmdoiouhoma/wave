<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\ListenProcessor;

/** `ListenResult` schema (DATA_MODEL §11). */
#[ApiResource(
    shortName: 'ListenResult',
    operations: [
        new Post(uriTemplate: '/tracks/{trackId}/listens', uriVariables: ['trackId'], name: 'reportListen', status: 200, read: false, deserialize: false, processor: ListenProcessor::class),
    ],
)]
final readonly class ListenResultView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public bool $counted,
        public int $playCount,
    ) {
    }
}
