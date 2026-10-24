<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\AccountTokenIssuer;
use App\Entity\AccountToken;
use App\Enum\AccountTokenPurpose;
use App\Message\SendVerificationEmail;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\Mime\Email;

/** W-17: email verification. */
final class EmailVerificationTest extends WaveApiTestCase
{
    public function testRegisterMailsAWorkingLink(): void
    {
        $response = $this->api('POST', '/api/v1/auth/register', null, ['json' => [
            'email' => 'new@example.test', 'password' => self::PASSWORD, 'username' => 'newbie', 'displayName' => 'Newbie',
        ]]);
        self::assertSame(201, $response->getStatusCode());
        $bearer = ['Authorization' => 'Bearer '.$this->json($response)['accessToken']];

        self::assertSame(1, $this->runQueued('async', SendVerificationEmail::class));
        $emails = $this->sentEmails();
        self::assertCount(1, $emails);
        self::assertSame('new@example.test', $emails[0]->getTo()[0]->getAddress());
        $token = $this->tokenFromEmail($emails[0], '/verify-email');

        // Only the hash is stored.
        $stored = $this->em()->getRepository(AccountToken::class)->findAll();
        self::assertCount(1, $stored);
        self::assertSame(hash('sha256', $token), $stored[0]->getTokenHash());

        $verify = $this->api('POST', '/api/v1/auth/verify-email', null, ['json' => ['token' => $token]]);
        self::assertSame(204, $verify->getStatusCode(), $verify->getContent(false));
        self::assertTrue($this->json($this->api('GET', '/api/v1/me', null, ['headers' => $bearer]))['emailVerified']);

        // Single use.
        $this->assertProblem($this->api('POST', '/api/v1/auth/verify-email', null, ['json' => ['token' => $token]]), 400, 'account_token_invalid');
    }

    public function testInvalidTokensAllAnswerTheSameCode(): void
    {
        $user = $this->createUser('alice', verified: false);
        $issuer = static::getContainer()->get(AccountTokenIssuer::class);
        $expired = $issuer->issue($user, AccountTokenPurpose::EmailVerification);
        $this->em()->getConnection()->executeStatement("UPDATE account_token SET expires_at = now() - interval '1 second'");
        $resetToken = $issuer->issue($user, AccountTokenPurpose::PasswordReset);

        foreach (['unknown-token', $expired, $resetToken, str_repeat('a', 129), ''] as $token) {
            $this->assertProblem($this->api('POST', '/api/v1/auth/verify-email', null, ['json' => ['token' => $token]]), 400, 'account_token_invalid');
        }
        $this->assertProblem($this->api('POST', '/api/v1/auth/verify-email', null, ['json' => []]), 400, 'account_token_invalid');
        self::assertFalse($this->reload($user)->isEmailVerified());
    }

    public function testNewTokenVoidsThePreviousOne(): void
    {
        $user = $this->createUser('alice', verified: false);
        $issuer = static::getContainer()->get(AccountTokenIssuer::class);
        $first = $issuer->issue($user, AccountTokenPurpose::EmailVerification);
        $second = $issuer->issue($user, AccountTokenPurpose::EmailVerification);

        $this->assertProblem($this->api('POST', '/api/v1/auth/verify-email', null, ['json' => ['token' => $first]]), 400, 'account_token_invalid');
        self::assertSame(204, $this->api('POST', '/api/v1/auth/verify-email', null, ['json' => ['token' => $second]])->getStatusCode());
    }

    public function testResendQueuesAMailUntilVerifiedAndIsRateLimited(): void
    {
        $user = $this->createUser('alice', verified: false);

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(202, $this->api('POST', '/api/v1/me/email-verification', $user)->getStatusCode());
        }
        self::assertCount(3, $this->queued('async', SendVerificationEmail::class));
        $this->assertProblem($this->api('POST', '/api/v1/me/email-verification', $user), 429, 'rate_limited');

        $verified = $this->createUser('bob');
        $this->assertProblem($this->api('POST', '/api/v1/me/email-verification', $verified), 409, 'email_already_verified');
    }

    public function testHandlerSkipsVerifiedOrDeletedUsers(): void
    {
        $verified = $this->createUser('bob');
        $bus = static::getContainer()->get('messenger.default_bus');
        $bus->dispatch(new SendVerificationEmail($verified->getId()->toRfc4122()), [new \Symfony\Component\Messenger\Stamp\ReceivedStamp('async')]);
        $bus->dispatch(new SendVerificationEmail('0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e'), [new \Symfony\Component\Messenger\Stamp\ReceivedStamp('async')]);

        self::assertCount(0, $this->sentEmails());
    }

    public function testUnverifiedUserIsBlockedOnGatedOperations(): void
    {
        $user = $this->createUser('alice', verified: false);

        self::assertFalse($this->json($this->api('GET', '/api/v1/me', $user))['emailVerified']);
        $this->assertProblem($this->api('POST', '/api/v1/me/avatar', $user, ['extra' => ['files' => []]]), 403, 'email_not_verified');
    }

    private function tokenFromEmail(Email $email, string $path): string
    {
        $text = (string) $email->getTextBody();
        self::assertSame(1, preg_match('#http://front\.test'.preg_quote($path, '#').'\?token=([A-Za-z0-9_-]+)#', $text, $m), $text);
        self::assertStringContainsString('http://front.test'.$path.'?token='.$m[1], (string) $email->getHtmlBody());

        return $m[1];
    }
}
