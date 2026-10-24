<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\AccountToken;
use App\Entity\User;
use App\Enum\AccountTokenPurpose;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\AccountTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * ADR 007 / DATA_MODEL §3b. Raw token = 32 random bytes base64url; only its SHA-256 is
 * stored. A new token voids older unused ones of the same purpose. Any failure on
 * consume is the same `account_token_invalid` (no oracle).
 */
final readonly class AccountTokenIssuer
{
    public function __construct(
        private AccountTokenRepository $tokens,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public static function ttl(AccountTokenPurpose $purpose): \DateInterval
    {
        return match ($purpose) {
            AccountTokenPurpose::EmailVerification => new \DateInterval('PT24H'),
            AccountTokenPurpose::PasswordReset => new \DateInterval('PT1H'),
        };
    }

    /** @return string the raw token: only the mail handler ever sees it */
    public function issue(User $user, AccountTokenPurpose $purpose): string
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = $this->clock->now();

        $this->em->wrapInTransaction(function () use ($user, $purpose, $raw, $now): void {
            $this->tokens->deleteUnused($user, $purpose);
            $this->em->persist(new AccountToken($user, $purpose, self::hash($raw), $now->add(self::ttl($purpose))));
            $this->em->flush();
        });

        return $raw;
    }

    /** Marks the token used and returns its user. Caller runs it inside its transaction. */
    public function consume(string $raw, AccountTokenPurpose $purpose): User
    {
        $token = '' === $raw || \strlen($raw) > 128 ? null : $this->tokens->findOneByHash(self::hash($raw));
        $now = $this->clock->now();
        if (null === $token || $token->getPurpose() !== $purpose || !$token->isUsable($now)) {
            throw new ApiProblemException(ProblemCode::AccountTokenInvalid, 'This link is invalid or has expired.');
        }
        // Single use even under concurrency: only one UPDATE can flip used_at.
        $updated = $this->em->getConnection()->executeStatement(
            'UPDATE account_token SET used_at = :now WHERE id = :id AND used_at IS NULL',
            ['now' => $now->format('Y-m-d H:i:s.uP'), 'id' => $token->getId()->toRfc4122()],
        );
        if (1 !== $updated) {
            throw new ApiProblemException(ProblemCode::AccountTokenInvalid, 'This link is invalid or has expired.');
        }
        $token->markUsed($now);
        $this->em->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($token), 'usedAt', $now);

        return $token->getUser();
    }

    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
