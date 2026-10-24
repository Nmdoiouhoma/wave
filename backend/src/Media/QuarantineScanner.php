<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Lists quarantine objects for CleanupQuarantine's orphan sweep.
 * ObjectStorage (CONVENTIONS §7) has no listing method, hence this port.
 */
interface QuarantineScanner
{
    /**
     * Keys under `quarantine/` in the private area last modified before $before.
     *
     * @return iterable<string>
     */
    public function keysModifiedBefore(\DateTimeImmutable $before): iterable;
}
