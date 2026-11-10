<?php

declare(strict_types=1);

namespace App\Mapper;

use App\ApiResource\CommentView;
use App\Entity\Comment;
use App\Entity\User;

/** `Comment` payloads; `canDelete` = comment author, post author or moderator (DATA_MODEL §10). */
final readonly class CommentMapper
{
    public function __construct(private UserMapper $users)
    {
    }

    public static function canDelete(Comment $comment, User $viewer): bool
    {
        return $viewer->isModerator()
            || $comment->getAuthor()->getId()->equals($viewer->getId())
            || $comment->getPost()->isAuthoredBy($viewer);
    }

    public function toView(Comment $comment, User $viewer): CommentView
    {
        return new CommentView(
            id: $comment->getId()->toRfc4122(),
            postId: $comment->getPost()->getId()->toRfc4122(),
            author: $this->users->toSummary($comment->getAuthor()),
            body: $comment->getBody(),
            moderation: $comment->getModeration()->value,
            createdAt: DateFormat::format($comment->getCreatedAt()),
            canDelete: self::canDelete($comment, $viewer),
        );
    }

    /**
     * @param list<Comment> $comments
     *
     * @return list<CommentView>
     */
    public function toViews(array $comments, User $viewer): array
    {
        return array_map(fn (Comment $c): CommentView => $this->toView($c, $viewer), $comments);
    }
}
