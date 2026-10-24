<?php

declare(strict_types=1);

namespace App\Media\Exception;

use App\Enum\FailureReason;

/**
 * The uploaded file is not acceptable (wrong type, codec, duration, size...).
 * Never retried: the handler marks the entity failed with $reason.
 */
final class MediaRejected extends \RuntimeException
{
    public function __construct(
        public readonly FailureReason $reason,
        string $detail,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(\sprintf('%s: %s', $reason->value, $detail), 0, $previous);
    }
}
