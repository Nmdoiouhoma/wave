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
        self::assertSame(180_000, ListenRules::windowMs(180_000), 'ADR 010: whole duration');
    }

    public function testShortTrackCountsAtHalf(): void
    {
        self::assertTrue(ListenRules::longEnough(10_000, 20_000));
        self::assertFalse(ListenRules::longEnough(9_999, 20_000));
        self::assertSame(30_000, ListenRules::windowMs(20_000));
    }

    /** W-26 F13 (ADR 010): grant freshness and elapsed time. */
    public function testGrantRules(): void
    {
        self::assertTrue(ListenRules::grantFresh(180_000 + 1_800_000, 180_000));
        self::assertFalse(ListenRules::grantFresh(180_000 + 1_800_001, 180_000));
        self::assertFalse(ListenRules::grantFresh(-1, 180_000));
        self::assertTrue(ListenRules::elapsedOk(62_000, 60_000));
        self::assertFalse(ListenRules::elapsedOk(62_001, 60_000));
    }
}
