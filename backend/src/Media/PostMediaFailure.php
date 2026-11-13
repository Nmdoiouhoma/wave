<?php

declare(strict_types=1);

namespace App\Media;

use App\Activity\ActivityJournal;
use App\Entity\Post;
use App\Enum\ActivityType;
use App\Enum\FailureReason;
use App\Enum\MediaStatus;
use App\Enum\ReportTargetType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Any failure of a post's media handler: `failed` + reason, under the row lock, only while the
 * post is still `processing` (the other handler then skips). Used by both handlers and by the
 * final-failure listener.
 */
final class PostMediaFailure
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?ActivityJournal $journal = null,
    ) {
    }

    public function mark(Uuid $postId, FailureReason $reason): void
    {
        $author = $this->em->wrapInTransaction(static function (EntityManagerInterface $em) use ($postId, $reason): ?Uuid {
            $post = LockedFind::find($em, Post::class, $postId);
            if (null === $post || MediaStatus::Processing !== $post->getStatus()) {
                return null;
            }
            $post->markFailed($reason);

            return $post->getAuthor()->getId();
        });
        // upload.rejected (§15); no journal when built by hand (final-failure listener, tests).
        if ($author instanceof Uuid) {
            $this->journal?->record(ActivityType::UploadRejected, $author, $author, ReportTargetType::Post, $postId, ['reason' => $reason]);
        }
    }
}
