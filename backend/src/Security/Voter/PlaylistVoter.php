<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Playlist;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * VIEW: public and visible, or owner, or moderator (denial → 404). EDIT: owner only (→ 403).
 *
 * @extends Voter<string, Playlist>
 */
final class PlaylistVoter extends Voter
{
    public const string VIEW = 'PLAYLIST_VIEW';
    public const string EDIT = 'PLAYLIST_EDIT';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Playlist && \in_array($attribute, [self::VIEW, self::EDIT], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $viewer = $user instanceof User ? $user : null;

        return match ($attribute) {
            self::VIEW => $subject->isVisibleTo($viewer),
            self::EDIT => $subject->isOwnedBy($viewer),
            default => false,
        };
    }
}
