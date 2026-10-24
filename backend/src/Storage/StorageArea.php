<?php

declare(strict_types=1);

namespace App\Storage;

/** ADR 003: two buckets, `wave-private` (audio, quarantine) and `wave-public` (images, videos). */
enum StorageArea: string
{
    case Private = 'private';
    case Public = 'public';
}
