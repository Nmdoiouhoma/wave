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
use App\State\Provider\PostLookup;
use App\State\Provider\TrackLookup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `PUT|DELETE /tracks/{trackId}/like`, `/playlists/{playlistId}/like` and `/posts/{postId}/like`:
 * idempotent; one can like only what one can see (404) — a post only when playable for the
 * caller (DATA_MODEL §9, FB-18) —, but one's own like can always be removed (F14). A new
 * track like asks for an affinity recompute once committed (FB-13).
 *
 * @implements ProcessorInterface<mixed, LikeStateView>
 */
final readonly class LikeProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private TrackLookup $tracks,
        private PlaylistLookup $playlists,
        private PostLookup $posts,
        private LikeRepository $likes,
        private AffinityTrigger $affinity,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): LikeStateView
    {
        $user = $this->currentUser->require();
        $kind = match (true) {
            isset($uriVariables['trackId']) => 'track',
            isset($uriVariables['postId']) => 'post',
            default => 'playlist',
        };
        $isTrack = 'track' === $kind;
        $rawId = $uriVariables[$kind.'Id'] ?? null;

        if ($operation instanceof DeleteOperationInterface) {
            // F14: my own like goes without a visibility check (I know the target exists).
            if (\is_string($rawId) && Uuid::isValid($rawId)) {
                $id = Uuid::fromString($rawId);
                $count = $this->em->wrapInTransaction(fn (): ?int => $this->likes->removeOwn($kind, $user, $id));
                if (null !== $count) {
                    return new LikeStateView(false, $count);
                }
            }
            // No like of mine: idempotent answer, but an invisible target stays 404 like an
            // unknown one (T17: no existence oracle on private playlists/tracks).
            $target = match ($kind) {
                'track' => $this->tracks->visible($rawId)->getId(),
                'post' => $this->posts->visible($rawId, $user)->getId(),
                default => $this->playlists->visible($rawId)->getId(),
            };

            return new LikeStateView(false, $this->em->wrapInTransaction(fn (): int => $this->likes->unlike($kind, $user, $target)));
        }

        $target = match ($kind) {
            'track' => $this->tracks->visible($rawId)->getId(),
            'post' => $this->posts->playable($rawId, $user)->getId(),
            default => $this->playlists->visible($rawId)->getId(),
        };
        [$changed, $count] = $this->em->wrapInTransaction(fn (): array => $this->likes->like($kind, $user, $target));
        // FB-13: after commit, best effort: the like never fails because of the recompute request.
        if ($changed && $isTrack) {
            $this->affinity->touch($user);
        }

        return new LikeStateView(true, $count);
    }
}
