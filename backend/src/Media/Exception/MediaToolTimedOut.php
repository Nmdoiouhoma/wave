<?php

declare(strict_types=1);

namespace App\Media\Exception;

/**
 * ffmpeg/ffprobe hit its wall-clock timeout (or its CPU-seconds rlimit).
 * Inputs are capped to ~10 min of audio, so this is never transient: callers
 * turn it into a final MediaRejected instead of retrying (W-66 F1).
 */
final class MediaToolTimedOut extends MediaToolFailed
{
}
