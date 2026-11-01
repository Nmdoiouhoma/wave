<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\FollowStateView;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\FollowRepository;
use App\Security\CurrentUser;
use App\State\Provider\UserLookup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `PUT|DELETE /users/{username}/follow`: idempotent, counters exact (DATA_MODEL §8).
 *
 * @implements ProcessorInterface<mixed, FollowStateView>
 */
final readonly class FollowProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserLookup $users,
        private FollowRepository $follows,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FollowStateView
    {
        $me = $this->currentUser->require();
        $target = $this->users->byUsername($uriVariables['username'] ?? null);

        if ($operation instanceof DeleteOperationInterface) {
            return new FollowStateView(false, $this->em->wrapInTransaction(fn (): int => $this->follows->unfollow($me, $target)));
        }
        if ($me->getId()->equals($target->getId())) {
            throw new ApiProblemException(ProblemCode::CannotFollowSelf, 'You cannot follow yourself.');
        }

        return new FollowStateView(true, $this->em->wrapInTransaction(fn (): int => $this->follows->follow($me, $target)));
    }
}
