<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Gate of operations flagged `x-requires-verified-email` (CONVENTIONS §2, ADR 007).
 * Operations declare `security: VERIFIED_EXPRESSION, securityMessage: DENIAL_MESSAGE`
 * so that the problem listener renders 403 `email_not_verified` instead of `forbidden`.
 *
 * @extends Voter<string, mixed>
 */
final class EmailVerifiedVoter extends Voter
{
    public const string ATTRIBUTE = 'EMAIL_VERIFIED';
    public const string DENIAL_MESSAGE = 'email_not_verified';
    public const string VERIFIED_EXPRESSION = "is_granted('ROLE_USER') and is_granted('EMAIL_VERIFIED')";

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ATTRIBUTE === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        return $user instanceof User && $user->isEmailVerified();
    }
}
