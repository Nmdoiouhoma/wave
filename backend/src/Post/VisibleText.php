<?php

declare(strict_types=1);

namespace App\Post;

/**
 * Trims user text (comment body, post caption) at both ends: Unicode whitespace and separators
 * (incl. NBSP) plus the invisible format characters a client can type or paste (zero-width
 * space/non-joiner/joiner U+200B-U+200D, word joiner U+2060, BOM U+FEFF). A text made only of
 * those is empty (W-37 F22).
 */
final class VisibleText
{
    private const string EDGES = '/^[\s\p{Z}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+|[\s\p{Z}\x{200B}-\x{200D}\x{2060}\x{FEFF}]+$/u';

    public static function trim(string $text): string
    {
        return (string) preg_replace(self::EDGES, '', $text);
    }
}
