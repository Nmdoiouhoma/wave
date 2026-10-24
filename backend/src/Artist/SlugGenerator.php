<?php

declare(strict_types=1);

namespace App\Artist;

use App\Repository\ArtistRepository;
use Symfony\Component\String\Slugger\AsciiSlugger;

/** DATA_MODEL §4: ascii-folded slug of the name, collisions get `-2`, `-3`…; never changes afterwards. */
final readonly class SlugGenerator
{
    private const int MAX_LENGTH = 80;
    private const int BASE_MAX_LENGTH = 72; // room for "-NNNNNNN"

    public function __construct(private ArtistRepository $artists)
    {
    }

    public static function base(string $name): string
    {
        $slug = strtolower(new AsciiSlugger()->slug($name)->toString());
        $slug = trim(substr($slug, 0, self::BASE_MAX_LENGTH), '-');

        return '' === $slug ? 'artist' : $slug;
    }

    public function generate(string $name): string
    {
        $base = self::base($name);
        $taken = array_flip($this->artists->findSlugsLike($base));
        if (!isset($taken[$base])) {
            return $base;
        }
        for ($i = 2;; ++$i) {
            $candidate = $base.'-'.$i;
            if (!isset($taken[$candidate]) && \strlen($candidate) <= self::MAX_LENGTH) {
                return $candidate;
            }
        }
    }
}
