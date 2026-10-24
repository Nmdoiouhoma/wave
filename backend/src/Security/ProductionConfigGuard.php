<?php

declare(strict_types=1);

namespace App\Security;

/**
 * F4/F6: in `prod` the kernel refuses to boot with development settings: refresh cookie
 * without `Secure`, placeholder secrets from `.env`/`.env.example`, or a stream signing key
 * equal to APP_SECRET (ADR 001 requires distinct keys).
 */
final class ProductionConfigGuard
{
    public const array SECRETS = ['APP_SECRET', 'STREAM_SIGNING_KEY', 'JWT_PASSPHRASE', 'S3_SECRET_KEY'];
    private const array PLACEHOLDERS = ['change-me', 'wavewave'];

    /**
     * @param array<string, mixed> $env
     *
     * @return list<string> problems (empty = safe)
     */
    public static function problems(array $env): array
    {
        $problems = [];
        if ('1' !== self::value($env, 'REFRESH_COOKIE_SECURE')) {
            $problems[] = 'REFRESH_COOKIE_SECURE must be 1 in prod (refresh cookie without Secure).';
        }
        foreach (self::SECRETS as $name) {
            $value = self::value($env, $name);
            if ('' === $value || str_starts_with($value, 'dev-only') || \in_array($value, self::PLACEHOLDERS, true)) {
                $problems[] = \sprintf('%s is empty or a development placeholder.', $name);
            }
        }
        $appSecret = self::value($env, 'APP_SECRET');
        if ('' !== $appSecret && $appSecret === self::value($env, 'STREAM_SIGNING_KEY')) {
            $problems[] = 'STREAM_SIGNING_KEY must differ from APP_SECRET.';
        }

        return $problems;
    }

    /**
     * @param array<string, mixed> $env
     *
     * @throws \RuntimeException when the production configuration is unsafe
     */
    public static function assertSafe(array $env): void
    {
        $problems = self::problems($env);
        if ([] !== $problems) {
            throw new \RuntimeException("Refusing to boot in prod:\n- ".implode("\n- ", $problems));
        }
    }

    /**
     * Environment as Symfony resolves it: $_ENV, then $_SERVER, then getenv().
     *
     * @return array<string, mixed>
     */
    public static function currentEnv(): array
    {
        $env = [];
        foreach ([...self::SECRETS, 'REFRESH_COOKIE_SECURE'] as $name) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            $env[$name] = false === $value ? null : $value;
        }

        return $env;
    }

    /** @param array<string, mixed> $env */
    private static function value(array $env, string $name): string
    {
        $value = $env[$name] ?? null;

        return \is_scalar($value) ? trim((string) $value) : '';
    }
}
