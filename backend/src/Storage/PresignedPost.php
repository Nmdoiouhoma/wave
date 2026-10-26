<?php

declare(strict_types=1);

namespace App\Storage;

/** Browser-facing S3 POST form (ADR 009): send every field, then the file as `file`, to `url`. */
final readonly class PresignedPost
{
    /** @param array<string, string> $fields */
    public function __construct(public string $url, public array $fields)
    {
    }
}
