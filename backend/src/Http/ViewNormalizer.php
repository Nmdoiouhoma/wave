<?php

declare(strict_types=1);

namespace App\Http;

/** Output DTO → array for the few plain controllers (auth); same shape API Platform renders. */
final class ViewNormalizer
{
    /** @return array<string, mixed> */
    public static function toArray(object $view): array
    {
        $out = [];
        foreach (get_object_vars($view) as $name => $value) {
            $out[$name] = self::normalize($value);
        }

        return $out;
    }

    private static function normalize(mixed $value): mixed
    {
        if (\is_object($value)) {
            return self::toArray($value);
        }
        if (\is_array($value)) {
            return array_map(self::normalize(...), $value);
        }

        return $value;
    }
}
