<?php

declare(strict_types=1);

namespace App\Catalog;

/**
 * Thrown by {@see CatalogProvider::artist()} when the provider is down and nothing is cached:
 * the artist route answers 503 `catalog_unavailable` with `Retry-After` (ADR 014 §7).
 */
final class CatalogUnavailable extends \RuntimeException
{
}
