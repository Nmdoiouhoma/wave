<?php

declare(strict_types=1);

namespace App\Artist;

use App\Entity\Artist;
use App\Problem\ApiProblemException;
use App\Repository\ArtistRepository;
use App\Security\Voter\ArtistVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Resolves `{slug}` with the visibility rule: a hidden artist is 404 except for its
 * owner and moderators; mutations by a viewer who can see it but is not the owner are 403.
 */
final readonly class ArtistLookup
{
    public function __construct(private ArtistRepository $artists, private AuthorizationCheckerInterface $auth)
    {
    }

    public function visible(mixed $slug): Artist
    {
        $artist = \is_string($slug) && 1 === preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug) && \strlen($slug) <= 80
            ? $this->artists->findOneBySlug($slug)
            : null;
        if (null === $artist) {
            throw ApiProblemException::notFound();
        }
        if (!$this->auth->isGranted(ArtistVoter::VIEW, $artist)) {
            throw ApiProblemException::notFound();
        }

        return $artist;
    }

    public function owned(mixed $slug): Artist
    {
        $artist = $this->visible($slug);
        if (!$this->auth->isGranted(ArtistVoter::EDIT, $artist)) {
            throw ApiProblemException::forbidden('Only the owner can do this.');
        }

        return $artist;
    }
}
