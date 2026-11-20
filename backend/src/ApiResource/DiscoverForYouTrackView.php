<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `DiscoverForYouTrack` schema (W-43, W-87): a For you track with the affinity that selected it. */
final readonly class DiscoverForYouTrackView
{
    public function __construct(
        public TrackView $track,
        public string $reasonType,
        public string $reasonLabel,
    ) {
    }
}
