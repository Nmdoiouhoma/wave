<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Activity\ActivityJournal;
use App\Enum\ActivityType;
use App\Enum\ReportTargetType;
use App\Post\PostCleanup;
use App\Problem\ApiProblemException;
use App\Security\CurrentUser;
use App\Security\TrackAudience;
use App\State\Provider\PostLookup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `DELETE /posts/{postId}`: the author whatever the visibility, or a moderator; anyone else
 * gets 404 for a post they cannot see and 403 for a visible one (DATA_MODEL §9).
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeletePostProcessor implements ProcessorInterface
{
    public function __construct(
        private PostLookup $lookup,
        private CurrentUser $currentUser,
        private PostCleanup $cleanup,
        private EntityManagerInterface $em,
        private TrackAudience $audience,
        private ActivityJournal $journal,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $viewer = $this->currentUser->require();
        $post = $this->lookup->find($uriVariables['postId'] ?? null);
        if (!$post->isAuthoredBy($viewer) && !$viewer->isModerator()) {
            throw $this->audience->isPostVisible($post, $viewer) ? ApiProblemException::forbidden('Only the author or a moderator can delete this post.') : ApiProblemException::notFound();
        }
        $postId = $post->getId();
        $authorId = $post->getAuthor()->getId();
        $this->em->wrapInTransaction(fn () => $this->cleanup->deletePost($postId));
        $this->em->detach($post);
        $this->journal->record(ActivityType::PostDeleted, $viewer, $authorId, ReportTargetType::Post, $postId);

        return null;
    }
}
