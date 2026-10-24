<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** ADR 006 / F7: vulnerable runtimes cannot be installed. */
final class ComposerConstraintsTest extends TestCase
{
    public function testAdr006Constraints(): void
    {
        $composer = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);

        self::assertSame('>=8.4.27', $composer['require']['php']);
        self::assertSame('8.4.27', $composer['config']['platform']['php']);
        self::assertSame('7.4.*', $composer['extra']['symfony']['require']);
        foreach (['symfony/security-http', 'symfony/security-bundle', 'symfony/http-kernel'] as $package) {
            self::assertSame('<7.4.13', $composer['conflict'][$package] ?? null, $package);
        }
        self::assertGreaterThanOrEqual(80427, \PHP_VERSION_ID);
    }
}
