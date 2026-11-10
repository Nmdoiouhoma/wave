<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\CloseFriendStateView;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\CloseFriendRepository;
use App\Security\CurrentUser;
use App\State\Provider\UserLookup;

/**
 * `PUT|DELETE /me/close-friends/{username}` (FB-18, DATA_MODEL §8): idempotent. PUT: unknown or
 * suspended → 404, not a follower (self included, or a concurrent unfollow) → 422
 * `not_a_follower`. DELETE works on a suspended user; 404 only for an unknown one.
 *
 * @implements ProcessorInterface<mixed, CloseFriendStateView>
 */
final readonly class CloseFriendProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserLookup $users,
        private CloseFriendRepository $closeFriends,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CloseFriendStateView
    {
        $me = $this->currentUser->require();
        if ($operation instanceof DeleteOperationInterface) {
            $this->closeFriends->remove($me, $this->users->includingSuspended($uriVariables['username'] ?? null));

            return new CloseFriendStateView(false);
        }
        $friend = $this->users->byUsername($uriVariables['username'] ?? null);
        if ($me->getId()->equals($friend->getId()) || !$this->closeFriends->add($me, $friend)) {
            throw new ApiProblemException(ProblemCode::NotAFollower, 'Only your followers can be in your close friends.');
        }

        return new CloseFriendStateView(true);
    }
}
