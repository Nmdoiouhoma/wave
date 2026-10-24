<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Message\SendPasswordResetEmail;
use App\Tests\Functional\WaveApiTestCase;

/** W-18: password reset. */
final class PasswordResetTest extends WaveApiTestCase
{
    public function testAnswerIsByteIdenticalWhateverTheAccount(): void
    {
        $this->createUser('alice');
        $suspended = $this->createUser('bob');
        $suspended->suspend();
        $this->em()->flush();

        $responses = [];
        foreach (['alice@example.test', 'nobody@example.test', 'bob@example.test'] as $email) {
            $response = $this->api('POST', '/api/v1/auth/password-reset', null, ['json' => ['email' => $email]]);
            $responses[] = [$response->getStatusCode(), $response->getContent(false), $response->getHeaders(false)['content-type'] ?? null];
        }

        self::assertSame(202, $responses[0][0]);
        self::assertSame('', $responses[0][1]);
        self::assertSame($responses[0], $responses[1]);
        self::assertSame($responses[0], $responses[2]);

        // The request only queues the lookup; the mail goes to the existing, active account only.
        self::assertSame(['alice@example.test', 'nobody@example.test', 'bob@example.test'], array_map(
            static fn (SendPasswordResetEmail $m): string => $m->email,
            $this->queued('async', SendPasswordResetEmail::class),
        ));
        $this->runQueued('async', SendPasswordResetEmail::class);
        $emails = $this->sentEmails();
        self::assertCount(1, $emails);
        self::assertSame('alice@example.test', $emails[0]->getTo()[0]->getAddress());
    }

    public function testEmailIsNormalizedAndValidated(): void
    {
        self::assertSame(202, $this->api('POST', '/api/v1/auth/password-reset', null, ['json' => ['email' => '  Alice@Example.TEST ']])->getStatusCode());
        self::assertSame('alice@example.test', $this->queued('async', SendPasswordResetEmail::class)[0]->email);

        $this->assertProblem($this->api('POST', '/api/v1/auth/password-reset', null, ['json' => ['email' => 'not-an-email']]), 422, 'validation_failed');
    }

    public function testConfirmSetsPasswordVerifiesEmailAndRevokesSessions(): void
    {
        $user = $this->createUser('alice', verified: false);
        $refresh = $this->json($this->api('POST', '/api/v1/auth/login', null, ['headers' => ['X-Wave-Client' => 'mobile'], 'json' => ['email' => 'alice@example.test', 'password' => self::PASSWORD]]))['refreshToken'];
        $token = $this->requestToken('alice@example.test');

        $this->assertProblem($this->api('POST', '/api/v1/auth/password-reset/confirm', null, ['json' => ['token' => $token, 'newPassword' => 'short']]), 422, 'validation_failed');

        $ok = $this->api('POST', '/api/v1/auth/password-reset/confirm', null, ['json' => ['token' => $token, 'newPassword' => 'my new long password']]);
        self::assertSame(204, $ok->getStatusCode(), $ok->getContent(false));
        self::assertTrue($this->reload($user)->isEmailVerified());

        $this->assertProblem($this->api('POST', '/api/v1/auth/refresh', null, ['headers' => ['X-Wave-Client' => 'mobile'], 'json' => ['refreshToken' => $refresh]]), 401, 'refresh_token_invalid');
        $this->assertProblem($this->api('POST', '/api/v1/auth/login', null, ['json' => ['email' => 'alice@example.test', 'password' => self::PASSWORD]]), 401, 'invalid_credentials');
        self::assertSame(200, $this->api('POST', '/api/v1/auth/login', null, ['json' => ['email' => 'alice@example.test', 'password' => 'my new long password']])->getStatusCode());

        // Single use.
        $this->assertProblem($this->api('POST', '/api/v1/auth/password-reset/confirm', null, ['json' => ['token' => $token, 'newPassword' => 'yet another password']]), 400, 'account_token_invalid');
    }

    public function testTokenExpiresAfterOneHour(): void
    {
        $this->createUser('alice');
        $token = $this->requestToken('alice@example.test');
        // Issued 61 minutes ago with the real 1 h TTL.
        $this->em()->getConnection()->executeStatement("UPDATE account_token SET created_at = now() - interval '61 minutes', expires_at = now() - interval '1 minute'");

        $this->assertProblem($this->api('POST', '/api/v1/auth/password-reset/confirm', null, ['json' => ['token' => $token, 'newPassword' => 'my new long password']]), 400, 'account_token_invalid');
    }

    public function testVerificationTokenCannotResetAPassword(): void
    {
        $user = $this->createUser('alice', verified: false);
        $raw = static::getContainer()->get(\App\Account\AccountTokenIssuer::class)->issue($user, \App\Enum\AccountTokenPurpose::EmailVerification);

        $this->assertProblem($this->api('POST', '/api/v1/auth/password-reset/confirm', null, ['json' => ['token' => $raw, 'newPassword' => 'my new long password']]), 400, 'account_token_invalid');
    }

    public function testRateLimitedPerEmail(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(202, $this->api('POST', '/api/v1/auth/password-reset', null, ['json' => ['email' => 'alice@example.test']])->getStatusCode());
        }
        $this->assertProblem($this->api('POST', '/api/v1/auth/password-reset', null, ['json' => ['email' => 'ALICE@example.test']]), 429, 'rate_limited');
        self::assertSame(202, $this->api('POST', '/api/v1/auth/password-reset', null, ['json' => ['email' => 'carol@example.test']])->getStatusCode());
    }

    private function requestToken(string $email): string
    {
        self::assertSame(202, $this->api('POST', '/api/v1/auth/password-reset', null, ['json' => ['email' => $email]])->getStatusCode());
        $this->runQueued('async', SendPasswordResetEmail::class);
        $emails = $this->sentEmails();
        self::assertNotEmpty($emails);
        $text = (string) end($emails)->getTextBody();
        self::assertSame(1, preg_match('#http://front\.test/reset-password\?token=([A-Za-z0-9_-]+)#', $text, $m), $text);

        return $m[1];
    }
}
