<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Transport `media`. Handler: media-infra (App\Media). ffprobe-validate the quarantined
 * upload, loudnorm, write `audio/tracks/{id}.m4a`, then Track::markReady()/markFailed().
 */
final readonly class ProcessTrack
{
    public function __construct(
        public string $trackId,
    ) {
    }
}
