<?php

declare(strict_types=1);

namespace App\Mapper;

use App\ApiResource\TrackView;
use App\Entity\Track;
use App\Entity\User;
use App\Storage\ObjectStorage;

/**
 * Track → `Track` schema. `likedByMe` is false until the like graph lands (W-22), which
 * will batch-load it here for a whole page (no N+1).
 */
final readonly class TrackMapper
{
    public function __construct(
        private ObjectStorage $storage,
        private UserMapper $users,
        private ArtistMapper $artists,
        private GenreMapper $genres,
    ) {
    }

    public function toView(Track $track, ?User $viewer): TrackView
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
            $track->getVisibility()->value,
            $track->isAllowClips(),
            $track->getModeration()->value,
            $track->getPlayCount(),
            $track->getLikeCount(),
            false,
            $track->isOwnedBy($viewer),
            $track->canBeClippedBy($viewer),
            DateFormat::format($track->getCreatedAt()),
        );
    }

    /**
     * @param list<Track> $tracks
     *
     * @return list<TrackView>
     */
    public function toViews(array $tracks, ?User $viewer): array
    {
        return array_map(fn (Track $t): TrackView => $this->toView($t, $viewer), $tracks);
    }
}
