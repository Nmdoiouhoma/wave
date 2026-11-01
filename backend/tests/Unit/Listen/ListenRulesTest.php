<?php

declare(strict_types=1);

namespace App\Tests\Unit\Listen;

use App\Listen\ListenRules;
use PHPUnit\Framework\TestCase;

/** W-23 AC: counting rules of DATA_MODEL §11. */
final class ListenRulesTest extends TestCase
{
    public function testThreeMinuteTrack(): void
    {
        self::assertFalse(ListenRules::longEnough(29_000, 180_000));
        self::assertTrue(ListenRules::longEnough(31_000, 180_000));
        self::assertTrue(ListenRules::longEnough(182_000, 180_000));
        self::assertFalse(ListenRules::longEnough(182_001, 180_000));
        self::assertSame(90_000, ListenRules::windowMs(180_000));
    }

    public function testShortTrackCountsAtHalf(): void
    {
        self::assertTrue(ListenRules::longEnough(10_000, 20_000));
        self::assertFalse(ListenRules::longEnough(9_999, 20_000));
        self::assertSame(30_000, ListenRules::windowMs(20_000));
    }
}
