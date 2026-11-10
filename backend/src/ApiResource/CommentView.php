<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Security\EmailVerifiedVoter;
use App\State\Processor\CreateCommentProcessor;
use App\State\Processor\DeleteCommentProcessor;
use App\State\Provider\CommentListProvider;

/** `Comment` schema. Visibility: DATA_MODEL §10 (invisible → 404 identical to an unknown id). */
#[ApiResource(
    shortName: 'Comment',
    operations: [
        new Get(uriTemplate: '/posts/{postId}/comments', uriVariables: ['postId'], name: 'listComments', output: CommentPage::class, provider: CommentListProvider::class),
        new Post(
            uriTemplate: '/posts/{postId}/comments',
            uriVariables: ['postId'],
            name: 'createComment',
            status: 201,
            read: false,
            deserialize: false,
            security: EmailVerifiedVoter::VERIFIED_EXPRESSION,
            securityMessage: EmailVerifiedVoter::DENIAL_MESSAGE,
            processor: CreateCommentProcessor::class,
        ),
        new Delete(uriTemplate: '/comments/{commentId}', uriVariables: ['commentId'], name: 'deleteComment', read: false, deserialize: false, output: false, processor: DeleteCommentProcessor::class),
    ],
)]
final readonly class CommentView
{
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $id,
        public string $postId,
        public UserSummary $author,
        public string $body,
        public string $moderation,
        public string $createdAt,
        public bool $canDelete,
    ) {
    }
}
