<?php

declare(strict_types=1);

namespace App\Artist;

use App\Entity\Artist;
use App\Entity\User;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\ArtistRepository;
use Doctrine\ORM\EntityManagerInterface;

/** Creates artists for `POST /artists` and for the first track upload (ADR 004). Caller flushes inside its transaction. */
final readonly class ArtistCreator
{
    public function __construct(
        private ArtistRepository $artists,
        private SlugGenerator $slugs,
        private EntityManagerInterface $em,
    ) {
    }

    public function create(User $owner, string $name, ?string $bio = null): Artist
    {
        // Serialise concurrent creations of the same owner (limit) — row lock on the user.
        $this->em->getConnection()->executeQuery('SELECT 1 FROM app_user WHERE id = :id FOR UPDATE', ['id' => $owner->getId()->toRfc4122()]);
        if ($this->artists->countByOwner($owner) >= Artist::MAX_PER_USER) {
            throw new ApiProblemException(ProblemCode::ArtistLimitReached, \sprintf('A user can manage at most %d artists.', Artist::MAX_PER_USER));
        }
        // Concurrent creations across owners may still pick the same slug: serialised by an advisory lock.
        $this->em->getConnection()->executeQuery("SELECT pg_advisory_xact_lock(hashtext('wave_artist_slug'))");
        $artist = new Artist($owner, $this->slugs->generate($name), $name, $bio);
        $this->em->persist($artist);
        $this->em->flush();

        return $artist;
    }
}
