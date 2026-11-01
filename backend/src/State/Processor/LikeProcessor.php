<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\LikeStateView;
use App\Recommendation\AffinityTrigger;
use App\Repository\LikeRepository;
use App\Security\CurrentUser;
use App\State\Provider\PlaylistLookup;
use App\State\Provider\TrackLookup;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `PUT|DELETE /tracks/{trackId}/like` and `/playlists/{playlistId}/like`: idempotent; one
 * can like only what one can see (404). A new track like asks for an affinity recompute.
 *
 * @implements ProcessorInterface<mixed, LikeStateView>
 */
final readonly class LikeProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private TrackLookup $tracks,
        private PlaylistLookup $playlists,
        private LikeRepository $likes,
        private AffinityTrigger $affinity,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LikeStateView
    {
        $user = $this->currentUser->require();
        $isTrack = isset($uriVariables['trackId']);
        $kind = $isTrack ? 'track' : 'playlist';
        $target = $isTrack
            ? $this->tracks->visible($uriVariables['trackId'])->getId()
            : $this->playlists->visible($uriVariables['playlistId'] ?? null)->getId();

        if ($operation instanceof DeleteOperationInterface) {
            $count = $this->em->wrapInTransaction(fn (): int => $this->likes->unlike($kind, $user, $target));

            return new LikeStateView(false, $count);
        }

        $count = $this->em->wrapInTransaction(function () use ($kind, $user, $target, $isTrack): int {
            [$changed, $count] = $this->likes->like($kind, $user, $target);
            if ($changed && $isTrack) {
                $this->affinity->touch($user);
            }

            return $count;
        });

        return new LikeStateView(true, $count);
    }
}
