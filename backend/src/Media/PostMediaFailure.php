<?php

declare(strict_types=1);

namespace App\Media;

use App\Entity\Post;
use App\Enum\FailureReason;
use App\Enum\MediaStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Any failure of a post's media handler: `failed` + reason, under the row lock, only while the
 * post is still `processing` (the other handler then skips). Used by both handlers and by the
 * final-failure listener.
 */
final class PostMediaFailure
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function mark(Uuid $postId, FailureReason $reason): void
    {
        $this->em->wrapInTransaction(static function (EntityManagerInterface $em) use ($postId, $reason): void {
            $post = LockedFind::find($em, Post::class, $postId);
            if (null !== $post && MediaStatus::Processing === $post->getStatus()) {
                $post->markFailed($reason);
            }
        });
    }
}
