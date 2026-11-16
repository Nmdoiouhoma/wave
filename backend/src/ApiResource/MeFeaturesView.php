<?php

declare(strict_types=1);

namespace App\ApiResource;

/** `Me.features` (ADR 014): server-side feature flags the front reads instead of probing. */
final readonly class MeFeaturesView
{
    public function __construct(
        public bool $catalog,
    ) {
    }
}
