<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\Processor\PostViewsProcessor;

/** One item of `PostViewsRequest`; `POST /post-views` answers 204 (DATA_MODEL §11). */
#[ApiResource(
    shortName: 'PostViewReport',
    operations: [
        new Post(uriTemplate: '/post-views', name: 'reportPostViews', status: 204, read: false, deserialize: false, output: false, processor: PostViewsProcessor::class),
    ],
)]
final readonly class PostViewReport
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $postId,
        public int $watchedMs,
    ) {
    }
}
