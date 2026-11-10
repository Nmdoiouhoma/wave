<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Track;
use App\Entity\User;
use App\Enum\MediaStatus;
use App\Security\TrackAudience;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * VIEW: DATA_MODEL §6 visibility, audiences included (TrackAudience; providers turn a denial
 * into 404, never 403).
 * EDIT: owner only. CLIP: D5. STREAM: visible and ready.
 *
 * @extends Voter<string, Track>
 */
final class TrackVoter extends Voter
{
    public const string VIEW = 'TRACK_VIEW';
    public const string EDIT = 'TRACK_EDIT';
    public const string CLIP = 'TRACK_CLIP';
    public const string STREAM = 'TRACK_STREAM';

    public function __construct(private readonly TrackAudience $audience)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject instanceof Track && \in_array($attribute, [self::VIEW, self::EDIT, self::CLIP, self::STREAM], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        $viewer = $user instanceof User ? $user : null;

        return match ($attribute) {
            self::VIEW => $this->audience->isTrackVisible($subject, $viewer),
            self::EDIT => $subject->isOwnedBy($viewer),
            self::CLIP => $subject->canBeClippedBy($viewer),
            self::STREAM => MediaStatus::Ready === $subject->getStatus() && $this->audience->isTrackVisible($subject, $viewer),
            default => false,
        };
    }
}
