<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\Entity\Comment;
use App\Entity\User;
use App\Enum\Moderation;
use App\Problem\ApiProblemException;
use App\Repository\CommentRepository;
use App\Security\TrackAudience;
use Symfony\Component\Uid\Uuid;

/**
 * DATA_MODEL §10 "comment visible to a caller": its post is visible to the caller and (moderator,
 * or the caller's comment, or visible and by a non-suspended author). Anything else is the same
 * 404 as an unknown id (delete, report).
 */
final readonly class CommentLookup
{
    public function __construct(private CommentRepository $comments, private TrackAudience $audience)
    {
    }

    public function visible(mixed $id, User $viewer): Comment
    {
        $comment = \is_string($id) && Uuid::isValid($id) ? $this->comments->findWithRelations(Uuid::fromString($id)) : null;
        $visible = null !== $comment
            && $this->audience->isPostVisible($comment->getPost(), $viewer)
            && ($viewer->isModerator()
                || $comment->getAuthor()->getId()->equals($viewer->getId())
                || (Moderation::Visible === $comment->getModeration() && !$comment->getAuthor()->isSuspended()));

        return null !== $comment && $visible ? $comment : throw ApiProblemException::notFound();
    }
}
