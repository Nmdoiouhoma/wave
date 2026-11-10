<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Security\EmailVerifiedVoter;
use App\State\Processor\CreatePostProcessor;
use App\State\Processor\DeletePostProcessor;
use App\State\Provider\PostListProvider;
use App\State\Provider\PostProvider;

/** `Post` schema. Visibility: DATA_MODEL §9 (non-visible → 404 identical to an unknown id). */
#[ApiResource(
    shortName: 'Post',
    operations: [
        new Post(
            uriTemplate: '/posts',
            name: 'createPost',
            status: 202,
            read: false,
            deserialize: false,
            inputFormats: ['multipart' => ['multipart/form-data']],
            security: EmailVerifiedVoter::VERIFIED_EXPRESSION,
            securityMessage: EmailVerifiedVoter::DENIAL_MESSAGE,
            processor: CreatePostProcessor::class,
        ),
        new Get(uriTemplate: '/posts/{postId}', uriVariables: ['postId'], name: 'getPost', provider: PostProvider::class),
        new Delete(uriTemplate: '/posts/{postId}', uriVariables: ['postId'], name: 'deletePost', read: false, deserialize: false, output: false, processor: DeletePostProcessor::class),
        new Get(uriTemplate: '/users/{username}/posts', uriVariables: ['username'], name: 'listUserPosts', output: PostPage::class, provider: PostListProvider::class),
    ],
)]
final readonly class PostView
{
    /** @param list<string> $hashtags */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public UserSummary $author,
        public TrackView $track,
        public int $clipStartMs,
        public int $clipDurationMs,
        public ?StreamUrlView $clip,
        public ?PostVisualView $visual,
        public ?string $caption,
        public array $hashtags,
        public string $status,
        public ?string $failureReason,
        public string $moderation,
        public int $likeCount,
        public int $commentCount,
        public int $viewCount,
        public bool $likedByMe,
        public bool $isOwner,
        public string $createdAt,
    ) {
    }
}
