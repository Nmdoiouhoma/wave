<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\RepostStateView;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\RepostRepository;
use App\Security\CurrentUser;
use App\Security\TrackAudience;
use App\State\Provider\PostLookup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `PUT|DELETE /posts/{postId}/repost` (ADR 015): idempotent. A repost is allowed only on a post
 * playable for the caller (404 otherwise), with a public audience (else 422 `repost_not_allowed`),
 * that is not the caller's own (else 422 `cannot_repost_own`). The caller's own repost can always
 * be undone, whatever the post's visibility (F14-style); without one, an invisible post is 404.
 *
 * @implements ProcessorInterface<mixed, RepostStateView>
 */
final readonly class RepostProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private PostLookup $posts,
        private TrackAudience $audience,
        private RepostRepository $reposts,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RepostStateView
    {
        $user = $this->currentUser->require();
        $rawId = $uriVariables['postId'] ?? null;

        if ($operation instanceof DeleteOperationInterface) {
            if (\is_string($rawId) && Uuid::isValid($rawId)) {
                $id = Uuid::fromString($rawId);
                $count = $this->em->wrapInTransaction(fn (): ?int => $this->reposts->removeOwn($user, $id));
                if (null !== $count) {
                    return new RepostStateView(false, $count);
                }
            }
            // No repost of mine: an invisible post stays 404 like an unknown one (no existence oracle).
            $post = $this->posts->visible($rawId, $user);

            return new RepostStateView(false, $this->em->wrapInTransaction(fn (): int => $this->reposts->unrepost($user, $post->getId())));
        }

        $post = $this->posts->find($rawId);
        if (!$this->audience->isPostPlayable($post, $user)) {
            throw ApiProblemException::notFound();
        }
        if ($post->isAuthoredBy($user)) {
            throw new ApiProblemException(ProblemCode::CannotRepostOwn, 'You cannot repost your own post.');
        }
        if (!TrackAudience::hasPublicRepostAudience($post)) {
            throw new ApiProblemException(ProblemCode::RepostNotAllowed, 'This post cannot be reposted.');
        }
        [, $count] = $this->em->wrapInTransaction(fn (): array => $this->reposts->repost($user, $post->getId()));

        return new RepostStateView(true, $count);
    }
}
