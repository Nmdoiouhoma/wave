<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Provider\HashtagPostsProvider;
use App\State\Provider\HashtagProvider;

/**
 * `Hashtag` schema (W-41). A hashtag exists for a caller only when at least one of its posts is
 * playable for them (DATA_MODEL §9); otherwise both routes answer 404, as for an unknown name.
 */
#[ApiResource(
    shortName: 'Hashtag',
    operations: [
        new Get(uriTemplate: '/hashtags/{name}', uriVariables: ['name'], name: 'getHashtag', provider: HashtagProvider::class),
        new Get(uriTemplate: '/hashtags/{name}/posts', uriVariables: ['name'], name: 'listHashtagPosts', output: PostPage::class, provider: HashtagPostsProvider::class),
    ],
)]
final readonly class HashtagView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $name,
        public int $postCount,
    ) {
    }
}
