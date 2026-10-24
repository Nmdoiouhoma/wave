<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Output DTOs (App\ApiResource\*, App\Pagination\*) are rendered as plain JSON objects
 * from their public properties — exactly the openapi.yaml schemas, nulls included —
 * bypassing API Platform's item normalizer (no IRIs, no Hydra, no identifier lookups).
 */
final class OutputViewNormalizer implements NormalizerInterface
{
    private const array NAMESPACES = ['App\\ApiResource\\', 'App\\Pagination\\'];

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        \assert(\is_object($data));

        return ViewNormalizer::toArray($data);
    }

    /** @param array<string, mixed> $context */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if (!\is_object($data)) {
            return false;
        }
        foreach (self::NAMESPACES as $namespace) {
            if (str_starts_with($data::class, $namespace)) {
                return true;
            }
        }

        return false;
    }

    public function getSupportedTypes(?string $format): array
    {
        return ['object' => false];
    }
}
