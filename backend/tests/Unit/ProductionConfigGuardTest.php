<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Kernel;
use App\Security\ProductionConfigGuard;
use PHPUnit\Framework\TestCase;

/** F4 + F6: prod refuses development settings. */
final class ProductionConfigGuardTest extends TestCase
{
    private const array SAFE = [
        'REFRESH_COOKIE_SECURE' => '1',
        'APP_SECRET' => 'a3f1c0de9b7e4f21a3f1c0de9b7e4f21',
        'STREAM_SIGNING_KEY' => '5e8d2b4a1c7f9e035e8d2b4a1c7f9e03',
        'JWT_PASSPHRASE' => '0b9c8d7e6f5a4b3c2d1e0f9a8b7c6d5e',
        'S3_SECRET_KEY' => '9f8e7d6c5b4a39281706f5e4d3c2b1a0',
    ];

    public function testSafeConfigurationPasses(): void
    {
        self::assertSame([], ProductionConfigGuard::problems(self::SAFE));
    }

    public function testInsecureCookieIsRejected(): void
    {
        foreach (['0', '', null] as $value) {
            self::assertCount(1, ProductionConfigGuard::problems(['REFRESH_COOKIE_SECURE' => $value] + self::SAFE));
        }
    }

    public function testPlaceholderSecretsAreRejected(): void
    {
        foreach (ProductionConfigGuard::SECRETS as $name) {
            foreach (['dev-only-whatever', 'change-me', 'wavewave', ''] as $value) {
                $problems = ProductionConfigGuard::problems([$name => $value] + self::SAFE);
                self::assertNotSame([], $problems, "{$name}={$value}");
                self::assertStringContainsString($name, $problems[0]);
            }
        }
    }

    public function testStreamKeyMustDifferFromAppSecret(): void
    {
        $problems = ProductionConfigGuard::problems(['STREAM_SIGNING_KEY' => self::SAFE['APP_SECRET']] + self::SAFE);

        self::assertSame(['STREAM_SIGNING_KEY must differ from APP_SECRET.'], $problems);
    }

    public function testProdKernelRefusesToBootWithTheCommittedDevDefaults(): void
    {
        $backup = $_ENV;
        try {
            $_ENV = ['REFRESH_COOKIE_SECURE' => '0', 'APP_SECRET' => 'dev-only-x', 'STREAM_SIGNING_KEY' => 'dev-only-y', 'JWT_PASSPHRASE' => 'dev-only-z', 'S3_SECRET_KEY' => 'wavewave'] + $_ENV;
            $kernel = new Kernel('prod', false);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Refusing to boot in prod');
            $kernel->boot();
        } finally {
            $_ENV = $backup;
        }
    }
}
