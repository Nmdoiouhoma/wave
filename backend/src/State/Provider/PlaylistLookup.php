<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\Entity\Playlist;
use App\Problem\ApiProblemException;
use App\Repository\PlaylistRepository;
use App\Security\Voter\PlaylistVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/** `{playlistId}` with the visibility rule (404 when not visible, 403 for non-owner mutations). */
final readonly class PlaylistLookup
{
    public function __construct(private PlaylistRepository $playlists, private AuthorizationCheckerInterface $auth)
    {
    }

    public function visible(mixed $id): Playlist
    {
        $playlist = \is_string($id) && Uuid::isValid($id) ? $this->playlists->findWithOwner(Uuid::fromString($id)) : null;
        if (null === $playlist || !$this->auth->isGranted(PlaylistVoter::VIEW, $playlist)) {
            throw ApiProblemException::notFound();
        }

        return $playlist;
    }

    public function owned(mixed $id): Playlist
    {
        $playlist = $this->visible($id);
        if (!$this->auth->isGranted(PlaylistVoter::EDIT, $playlist)) {
            throw ApiProblemException::forbidden('Only the owner can do this.');
        }

        return $playlist;
    }
}
