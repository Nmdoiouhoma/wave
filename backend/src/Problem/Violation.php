<?php

declare(strict_types=1);

namespace App\Problem;

final readonly class Violation
{
    public function __construct(
        public string $propertyPath,
        public string $message,
        public ?string $code = null,
    ) {
    }
}
