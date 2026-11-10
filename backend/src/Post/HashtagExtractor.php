<?php

declare(strict_types=1);

namespace App\Post;

/**
 * DATA_MODEL §9: hashtags extracted from the caption at creation. The caption is NFKC
 * normalised, `#([\p{L}\p{N}_]{1,50})` matched, names lowercased (then NFKC again: lowercasing
 * can denormalise), deduplicated in order of appearance, the first 10 kept, extras ignored.
 * `#Lo_Fi #lofi #LOFI` → [`lo_fi`, `lofi`].
 */
final class HashtagExtractor
{
    public const int MAX = 10;
    public const string NAME_PATTERN = '/^[\p{L}\p{N}_]{1,50}$/u';

    /** @return list<string> */
    public static function extract(?string $caption): array
    {
        if (null === $caption || '' === $caption) {
            return [];
        }
        $text = \Normalizer::normalize($caption, \Normalizer::FORM_KC);
        if (false === $text || false === preg_match_all('/#([\p{L}\p{N}_]{1,50})/u', $text, $matches)) {
            return [];
        }
        $names = [];
        foreach ($matches[1] as $raw) {
            $name = \Normalizer::normalize(mb_strtolower($raw, 'UTF-8'), \Normalizer::FORM_KC);
            if (false === $name || 1 !== preg_match(self::NAME_PATTERN, $name) || \in_array($name, $names, true)) {
                continue;
            }
            $names[] = $name;
            if (self::MAX === \count($names)) {
                break;
            }
        }

        return $names;
    }
}
