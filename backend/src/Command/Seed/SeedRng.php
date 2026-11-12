<?php

declare(strict_types=1);

namespace App\Command\Seed;

/** Tiny deterministic generator (xorshift32): no global state, same sequence on every run and platform. */
final class SeedRng
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = ($seed & 0x7FFFFFFF) ?: 0x2545F491;
        for ($i = 0; $i < 4; ++$i) {
            $this->next();
        }
    }

    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;

        return $this->state;
    }

    public function float(): float
    {
        return $this->next() / 4294967296.0;
    }

    public function between(int $min, int $max): int
    {
        return $min + (int) ($this->float() * ($max - $min + 1));
    }
}
