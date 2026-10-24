<?php

declare(strict_types=1);

namespace App\Media\Exception;

/**
 * ffmpeg/ffprobe failed on input that was already validated (or timed out).
 * Treated as transient: the message is retried, then the entity is marked
 * failed with `processing_error` (see MarkMediaFailedOnFinalFailure).
 */
final class MediaToolFailed extends \RuntimeException
{
}
