<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\Entity\Track;
use App\Problem\ApiProblemException;
use App\Repository\TrackRepository;
use App\Security\Voter\TrackVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/** `{trackId}` with the visibility rule (404 when not visible, 403 for non-owner mutations). */
final readonly class TrackLookup
{
    public function __construct(private TrackRepository $tracks, private AuthorizationCheckerInterface $auth)
    {
    }

    public function visible(mixed $id): Track
    {
        $track = \is_string($id) && Uuid::isValid($id) ? $this->tracks->findWithRelations(Uuid::fromString($id)) : null;
        if (null === $track || !$this->auth->isGranted(TrackVoter::VIEW, $track)) {
            throw ApiProblemException::notFound();
        }

        return $track;
    }

    public function owned(mixed $id): Track
    {
        $track = $this->visible($id);
        if (!$this->auth->isGranted(TrackVoter::EDIT, $track)) {
            throw ApiProblemException::forbidden('Only the owner can do this.');
        }

        return $track;
    }
}
