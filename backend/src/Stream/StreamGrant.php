<?php

declare(strict_types=1);

namespace App\Stream;

use Symfony\Component\Uid\Uuid;

final readonly class StreamGrant
{
    public function __construct(
        public string $kind,
        public Uuid $id,
        public Uuid $viewerId,
    ) {
    }
}
