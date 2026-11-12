<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Mapper\CommentMapper;
use App\Problem\ApiProblemException;
use App\Repository\CommentRepository;
use App\Security\CurrentUser;
use App\State\Provider\CommentLookup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `DELETE /comments/{commentId}` (204). DATA_MODEL §10: a comment the caller cannot see (post
 * not visible, or hidden / by a suspended author for someone else) is the same 404 as an unknown
 * id; visible but neither the caller's comment, nor on the caller's post, nor moderator → 403.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteCommentProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private CommentRepository $comments,
        private CommentLookup $lookup,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $viewer = $this->currentUser->require();
        $comment = $this->lookup->visible($uriVariables['commentId'] ?? null, $viewer);
        if (!CommentMapper::canDelete($comment, $viewer)) {
            throw ApiProblemException::forbidden('Only the comment author, the post author or a moderator can delete this comment.');
        }

        $this->em->wrapInTransaction(fn () => $this->comments->delete($comment->getId()));
        $this->em->detach($comment);

        return null;
    }
}
