<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Artist;
use App\Entity\User;
use App\Enum\Moderation;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * VIEW: hidden artists only for owner and moderators (denial → 404). EDIT: owner only (→ 403).
 *
 * @extends Voter<string, Artist>
 */
final class ArtistVoter extends Voter
{
    public const string VIEW = 'ARTIST_VIEW';
    public const string EDIT = 'ARTIST_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Artist && \in_array($attribute, [self::VIEW, self::EDIT], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $viewer = $user instanceof User ? $user : null;

        return match ($attribute) {
            self::VIEW => Moderation::Visible === $subject->getModeration() || $subject->isOwnedBy($viewer) || (null !== $viewer && $viewer->isModerator()),
            self::EDIT => $subject->isOwnedBy($viewer),
            default => false,
        };
    }
}
