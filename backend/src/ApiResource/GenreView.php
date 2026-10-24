<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\State\Provider\GenreProvider;

/** `Genre` schema. */
#[ApiResource(
    shortName: 'Genre',
    operations: [
        new GetCollection(
            uriTemplate: '/genres',
            name: 'listGenres',
            provider: GenreProvider::class,
            cacheHeaders: ['max_age' => 86400, 'public' => true, 'shared_max_age' => 86400],
        ),
    ],
)]
final readonly class GenreView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $name,
    ) {
    }
}
