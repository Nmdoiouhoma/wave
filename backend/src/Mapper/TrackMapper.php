<?php

declare(strict_types=1);

namespace App\Mapper;

use App\ApiResource\TrackView;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use App\Repository\LikeRepository;
use App\Storage\ObjectStorage;

/**
 * Track → `Track` schema (also embedded in `Post.track`). `likedByMe` is batch-loaded for a whole
 * page (one query, W-22). `visibility`: the real audience for the owner and moderators only,
 * else `public` or null (ADR 012 §7: a restricted audience is not disclosed).
 */
final readonly class TrackMapper
{
    public function __construct(
        private ObjectStorage $storage,
        private UserMapper $users,
        private ArtistMapper $artists,
        private GenreMapper $genres,
        private LikeRepository $likes,
    ) {
    }

    public function toView(Track $track, ?User $viewer): TrackView
    {
        return $this->toViews([$track], $viewer)[0];
    }

    /**
     * @param list<Track> $tracks
     *
     * @return list<TrackView>
     */
    public function toViews(array $tracks, ?User $viewer): array
    {
        $liked = $this->likes->likedAmong('track', $viewer, array_map(static fn (Track $t): \Symfony\Component\Uid\Uuid => $t->getId(), $tracks));

        return array_map(fn (Track $t): TrackView => $this->build($t, $viewer, isset($liked[$t->getId()->toRfc4122()])), $tracks);
    }

    private function visibilityFor(Track $track, ?User $viewer): ?string
    {
        if ($track->isOwnedBy($viewer) || (null !== $viewer && $viewer->isModerator())) {
            return $track->getVisibility()->value;
        }

        return Visibility::Public === $track->getVisibility() ? Visibility::Public->value : null;
    }

    private function build(Track $track, ?User $viewer, bool $likedByMe): TrackView
    {
        $cover = $track->getCoverKey();

        return new TrackView(
            $track->getId()->toRfc4122(),
            $track->getTitle(),
            $this->artists->toSummary($track->getArtist()),
            $this->users->toSummary($track->getOwner()),
            $this->genres->toView($track->getGenre()),
            null === $cover ? null : $this->storage->publicUrl($cover),
            $track->getDurationMs(),
            $track->getStatus()->value,
            $track->getFailureReason()?->value,
            $this->visibilityFor($track, $viewer),
            $track->isAllowClips(),
            $track->getModeration()->value,
            $track->getPlayCount(),
            $track->getLikeCount(),
            $likedByMe,
            $track->isOwnedBy($viewer),
            $track->canBeClippedBy($viewer),
            DateFormat::format($track->getCreatedAt()),
        );
    }
}
