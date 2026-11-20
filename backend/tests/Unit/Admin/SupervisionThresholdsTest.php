<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\SupervisionThresholds as T;
use App\Enum\SupervisionLevel as L;
use App\State\Provider\AdminSupervisionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** W-88 (FB-34): every supervision threshold boundary, the worst-wins rollup and DST buckets. */
final class SupervisionThresholdsTest extends TestCase
{
    /** @return iterable<string, array{int, int, L}> failed, succeeded, expected */
    public static function loginCases(): iterable
    {
        yield 'below min attempts, all failed' => [19, 0, L::Ok];
        yield 'min attempts reached, 0 %' => [0, 20, L::Ok];
        yield '19 % ok' => [19, 81, L::Ok];
        yield '20 % attention' => [20, 80, L::Attention];
        yield '49 % attention' => [49, 51, L::Attention];
        yield '50 % alerte' => [50, 50, L::Alerte];
        yield '79 % alerte' => [79, 21, L::Alerte];
        yield '80 % critique' => [80, 20, L::Critique];
        yield '100 % at the floor' => [20, 0, L::Critique];
    }

    #[DataProvider('loginCases')]
    public function testLoginLevel(int $failed, int $succeeded, L $expected): void
    {
        self::assertSame($expected, T::loginLevel($failed, $succeeded));
    }

    public function testLoginFloorForcesZeroAndUnmeasured(): void
    {
        self::assertFalse(T::loginMeasured(19, 0));
        self::assertSame(0.0, T::loginRate(19, 0));
        self::assertTrue(T::loginMeasured(10, 10));
        self::assertSame(0.5, T::loginRate(10, 10));
        self::assertSame(0.0, T::loginRate(0, 0), 'no division by zero');
    }

    /** @return iterable<string, array{int, L}> */
    public static function countCases(): iterable
    {
        yield '0 ok' => [0, L::Ok];
        yield '1 attention' => [1, L::Attention];
        yield '9 attention' => [9, L::Attention];
        yield '10 alerte' => [10, L::Alerte];
        yield '49 alerte' => [49, L::Alerte];
        yield '50 critique' => [50, L::Critique];
    }

    #[DataProvider('countCases')]
    public function testAbuseAndUploadSteps(int $count, L $expected): void
    {
        self::assertSame($expected, T::abuseLevel($count));
        self::assertSame($expected, T::uploadLevel($count));
    }

    public function testSeverityMappingAndWorstWins(): void
    {
        self::assertSame(L::Critique, L::fromSeverity('high'));
        self::assertSame(L::Alerte, L::fromSeverity('medium'));
        self::assertSame(L::Attention, L::fromSeverity('low'));
        self::assertSame(L::Ok, L::worst());
        self::assertSame(L::Alerte, L::worst(L::Ok, L::Alerte, L::Attention));
        self::assertSame(L::Critique, L::worst(L::Critique, L::Ok));
    }

    public function testHourStartsAcrossAutumnDstAre24DistinctHours(): void
    {
        // 2026-10-25: Paris goes 03:00 CEST → 02:00 CET (a 25 h local day).
        $now = new \DateTimeImmutable('2026-10-25 12:34:56', new \DateTimeZone('Europe/Paris'));
        $starts = AdminSupervisionProvider::hourStarts($now);

        self::assertCount(24, $starts);
        self::assertSame('2026-10-25T11:00:00+00:00', $starts[23]->format('c'), 'current hour = 12 h CET = 11:00Z');
        for ($i = 1; $i < 24; ++$i) {
            self::assertSame(3600, $starts[$i]->getTimestamp() - $starts[$i - 1]->getTimestamp());
        }
        $paris = new \DateTimeZone('Europe/Paris');
        $localHours = array_map(static fn (\DateTimeImmutable $d): string => $d->setTimezone($paris)->format('H'), $starts);
        self::assertCount(2, array_keys($localHours, '02', true), 'local 02 h appears twice');
    }

    public function testHourStartsAcrossSpringDstSkipLocalTwoOClock(): void
    {
        // 2026-03-29: Paris goes 02:00 CET → 03:00 CEST (a 23 h local day).
        $now = new \DateTimeImmutable('2026-03-29 12:00:00', new \DateTimeZone('Europe/Paris'));
        $starts = AdminSupervisionProvider::hourStarts($now);
        $paris = new \DateTimeZone('Europe/Paris');
        $localHours = array_map(static fn (\DateTimeImmutable $d): string => $d->setTimezone($paris)->format('H'), $starts);

        self::assertCount(24, $starts);
        self::assertNotContains('02', $localHours, 'local 02 h does not exist that day');
        self::assertCount(2, array_keys($localHours, '12', true), '24 h back from 12 h spans 13 h the day before');
    }
}
