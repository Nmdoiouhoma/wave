<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Enum\AccountTokenPurpose;
use App\Message\SendPasswordResetEmail;
use Doctrine\DBAL\Connection;

/**
 * W-16: password-reset anti-enumeration and rate limits; account tokens (single use,
 * expiry, purpose binding, brute force).
 */
final class AccountTokenSecurityTest extends SecurityTestCase
{
    public function testPasswordResetResponseIsIdenticalForKnownUnknownAndSuspendedEmails(): void
    {
        $this->createUser('alice');
        $suspended = $this->createUser('sam');
        $suspended->suspend();
        $this->em()->flush();

        $fingerprints = [];
        foreach (['alice@example.test', 'nobody-here@example.test', 'sam@example.test', 'ALICE@Example.test '] as $i => $email) {
            // Distinct IPs: this test is about the response shape, not the IP limiter.
            $r = $this->rawJson('POST', '/api/v1/auth/password-reset', ['email' => $email], ['REMOTE_ADDR' => '198.51.100.'.($i + 1)]);
            $headers = $r->headers->all();
            unset($headers['date'], $headers['x-debug-token'], $headers['x-debug-token-link']);
            ksort($headers);
            $fingerprints[$email] = [$r->getStatusCode(), $r->getContent(), $headers];
        }

        self::assertSame(202, $fingerprints['alice@example.test'][0]);
        self::assertSame('', $fingerprints['alice@example.test'][1]);
        self::assertCount(1, array_unique(array_map('serialize', $fingerprints)), 'status, body and headers must not depend on the account');

        // No lookup on the request path: every request only queues the same message type.
        self::assertCount(4, $this->queued('async', SendPasswordResetEmail::class));
    }

    /**
     * Timing: the HTTP path does no user lookup, so known vs unknown emails must cost the
     * same up to noise. Medians over 15 alternating samples, 15 ms tolerance.
     */
    public function testPasswordResetTimingDoesNotDependOnExistence(): void
    {
        $this->createUser('alice');
        $known = [];
        $unknown = [];
        for ($i = 0; $i < 15; ++$i) {
            foreach (['known' => 'alice@example.test', 'unknown' => 'ghost'.$i.'@example.test'] as $kind => $email) {
                $t = hrtime(true);
                $r = $this->rawJson('POST', '/api/v1/auth/password-reset', ['email' => $email], ['REMOTE_ADDR' => '10.9.'.$i.'.'.('known' === $kind ? 1 : 2)]);
                $ms = (hrtime(true) - $t) / 1e6;
                // alice's per-email limiter (3/h) kicks in after 3 requests: compare 202s only.
                if (202 !== $r->getStatusCode()) {
                    continue;
                }
                if ('known' === $kind) {
                    $known[] = $ms;
                } else {
                    $unknown[] = $ms;
                }
            }
        }
        self::assertNotEmpty($known);
        $median = static function (array $v): float {
            sort($v);

            return $v[intdiv(\count($v), 2)];
        };
        self::assertLessThan(15.0, abs($median($known) - $median($unknown)), \sprintf('known %.2f ms vs unknown %.2f ms', $median($known), $median($unknown)));
    }

    public function testPasswordResetIsLimitedPerEmailWhateverCaseOrSpacing(): void
    {
        $variants = ['victim@example.test', 'VICTIM@example.test', ' victim@EXAMPLE.test ', 'Victim@Example.Test'];
        $codes = [];
        foreach ($variants as $i => $email) {
            $codes[] = $this->rawJson('POST', '/api/v1/auth/password-reset', ['email' => $email], ['REMOTE_ADDR' => '203.0.113.'.($i + 1)])->getStatusCode();
        }
        self::assertSame([202, 202, 202, 429], $codes, 'limit 3/h per normalized email, independent of IP');
    }

    public function testPasswordResetIsLimitedPerIp(): void
    {
        $codes = [];
        for ($i = 0; $i < 11; ++$i) {
            $codes[] = $this->rawJson('POST', '/api/v1/auth/password-reset', ['email' => "spray{$i}@example.test"], ['REMOTE_ADDR' => '203.0.113.50'])->getStatusCode();
        }
        self::assertSame(array_merge(array_fill(0, 10, 202), [429]), $codes);
    }

    /** Behind the trusted proxy, a client-supplied X-Forwarded-For must not mint new identities. */
    public function testPasswordResetIpLimitCannotBeResetWithForwardedForFromUntrustedClient(): void
    {
        $codes = [];
        for ($i = 0; $i < 11; ++$i) {
            $codes[] = $this->rawJson('POST', '/api/v1/auth/password-reset', ['email' => "xff{$i}@example.test"], [
                'REMOTE_ADDR' => '203.0.113.60',
                'HTTP_X_FORWARDED_FOR' => '192.0.2.'.$i,
            ])->getStatusCode();
        }
        self::assertSame(429, end($codes));
    }

    public function testResetTokenIsSingleUse(): void
    {
        $alice = $this->createUser('alice');
        $token = $this->issueAccountToken($alice, AccountTokenPurpose::PasswordReset);

        $first = $this->rawJson('POST', '/api/v1/auth/password-reset/confirm', ['token' => $token, 'newPassword' => 'brand new password 1']);
        self::assertSame(204, $first->getStatusCode(), (string) $first->getContent());
        $second = $this->rawJson('POST', '/api/v1/auth/password-reset/confirm', ['token' => $token, 'newPassword' => 'attacker password 2']);
        self::assertSame(400, $second->getStatusCode());
        self::assertSame('account_token_invalid', self::problemCode($second));
    }

    public function testExpiredResetTokenIsRejectedWithTheSameError(): void
    {
        $alice = $this->createUser('alice');
        $token = $this->issueAccountToken($alice, AccountTokenPurpose::PasswordReset);
        static::getContainer()->get(Connection::class)->executeStatement("UPDATE account_token SET expires_at = clock_timestamp() - interval '1 second'");
        $this->em()->clear();

        $r = $this->rawJson('POST', '/api/v1/auth/password-reset/confirm', ['token' => $token, 'newPassword' => 'brand new password 1']);
        self::assertSame(400, $r->getStatusCode());
        self::assertSame('account_token_invalid', self::problemCode($r));
    }

    public function testTokenIsBoundToItsPurpose(): void
    {
        $alice = $this->createUser('alice', verified: false);
        $verification = $this->issueAccountToken($alice, AccountTokenPurpose::EmailVerification);
        $reset = $this->issueAccountToken($alice, AccountTokenPurpose::PasswordReset);

        $r = $this->rawJson('POST', '/api/v1/auth/password-reset/confirm', ['token' => $verification, 'newPassword' => 'takeover password 1']);
        self::assertSame(400, $r->getStatusCode(), 'a verification link must not reset the password');
        self::assertSame('account_token_invalid', self::problemCode($r));

        $r = $this->rawJson('POST', '/api/v1/auth/verify-email', ['token' => $reset]);
        self::assertSame(400, $r->getStatusCode());
        self::assertFalse($this->reload($alice)->isEmailVerified());
    }

    public function testNewerResetTokenVoidsTheOlderOne(): void
    {
        $alice = $this->createUser('alice');
        $old = $this->issueAccountToken($alice, AccountTokenPurpose::PasswordReset);
        $this->issueAccountToken($alice, AccountTokenPurpose::PasswordReset);

        $r = $this->rawJson('POST', '/api/v1/auth/password-reset/confirm', ['token' => $old, 'newPassword' => 'brand new password 1']);
        self::assertSame(400, $r->getStatusCode());
    }

    public function testMalformedTokensAllAnswerTheSameError(): void
    {
        foreach (['', str_repeat('A', 129), 'not-a-token', "a\u{0000}b"] as $token) {
            $r = $this->rawJson('POST', '/api/v1/auth/verify-email', ['token' => $token], ['REMOTE_ADDR' => '198.51.100.'.random_int(1, 250)]);
            self::assertSame(400, $r->getStatusCode(), var_export($token, true));
            self::assertSame('account_token_invalid', self::problemCode($r));
        }
        // Wrong JSON type is a token error too (no 422 oracle on the token field).
        $r = $this->rawJson('POST', '/api/v1/auth/verify-email', ['token' => ['x']], ['REMOTE_ADDR' => '198.51.100.251']);
        self::assertSame('account_token_invalid', self::problemCode($r));
    }

    public function testTokenBruteForceIsRateLimitedPerIp(): void
    {
        $verify = [];
        for ($i = 0; $i < 11; ++$i) {
            $verify[] = $this->rawJson('POST', '/api/v1/auth/verify-email', ['token' => self::b64(random_bytes(32))], ['REMOTE_ADDR' => '203.0.113.70'])->getStatusCode();
        }
        self::assertSame(array_merge(array_fill(0, 10, 400), [429]), $verify);

        $reset = [];
        for ($i = 0; $i < 11; ++$i) {
            $reset[] = $this->rawJson('POST', '/api/v1/auth/password-reset/confirm', ['token' => self::b64(random_bytes(32)), 'newPassword' => 'whatever password'], ['REMOTE_ADDR' => '203.0.113.71'])->getStatusCode();
        }
        self::assertSame(array_merge(array_fill(0, 10, 400), [429]), $reset);
    }

    public function testRawTokenIsNeverStoredNorQueued(): void
    {
        $alice = $this->createUser('alice');
        $token = $this->issueAccountToken($alice, AccountTokenPurpose::PasswordReset);
        $conn = static::getContainer()->get(Connection::class);

        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM account_token WHERE token_hash = :t', ['t' => $token]));
        self::assertSame(0, (int) $conn->fetchOne("SELECT count(*) FROM messenger_messages WHERE body LIKE '%' || :t || '%'", ['t' => $token]));
    }

    public function testResetRevokesEveryRefreshToken(): void
    {
        $alice = $this->createUser('alice');
        $login = $this->rawJson('POST', '/api/v1/auth/login', ['email' => 'alice@example.test', 'password' => self::PASSWORD], ['HTTP_X_WAVE_CLIENT' => 'mobile']);
        $refresh = json_decode((string) $login->getContent(), true)['refreshToken'];

        $token = $this->issueAccountToken($alice, AccountTokenPurpose::PasswordReset);
        self::assertSame(204, $this->rawJson('POST', '/api/v1/auth/password-reset/confirm', ['token' => $token, 'newPassword' => 'brand new password 1'])->getStatusCode());

        $r = $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $refresh], ['HTTP_X_WAVE_CLIENT' => 'mobile']);
        self::assertSame(401, $r->getStatusCode());
    }
}
