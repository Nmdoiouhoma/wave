<?php

declare(strict_types=1);

namespace App\Doctrine\Listener;

use App\Entity\Artist;
use App\Entity\Track;
use App\Repository\ArtistRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Keeps `artist.track_count` (recount, DATA_MODEL §4) exact. post* events fire inside
 * the flush transaction, so the recount commits atomically with the track change —
 * including Track::markReady()/markFailed() called by media handlers (CONVENTIONS §8).
 */
#[AsEntityListener(event: Events::postPersist, entity: Track::class)]
#[AsEntityListener(event: Events::preUpdate, entity: Track::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Track::class)]
#[AsEntityListener(event: Events::postRemove, entity: Track::class)]
final class TrackCountListener
{
    /** @var array<string, Artist> previous artist per track id, captured in preUpdate */
    private array $previousArtist = [];

    public function __construct(private readonly ArtistRepository $artists)
    {
    }

    public function postPersist(Track $track, PostPersistEventArgs $args): void
    {
        $this->recount($args->getObjectManager(), $track->getArtist());
    }

    public function preUpdate(Track $track, PreUpdateEventArgs $args): void
    {
        if ($args->hasChangedField('artist')) {
            $old = $args->getOldValue('artist');
            if ($old instanceof Artist) {
                $this->previousArtist[$track->getId()->toRfc4122()] = $old;
            }
        }
    }

    public function postUpdate(Track $track, PostUpdateEventArgs $args): void
    {
        $key = $track->getId()->toRfc4122();
        if (isset($this->previousArtist[$key])) {
            $this->recount($args->getObjectManager(), $this->previousArtist[$key]);
            unset($this->previousArtist[$key]);
        }
        $this->recount($args->getObjectManager(), $track->getArtist());
    }

    public function postRemove(Track $track, PostRemoveEventArgs $args): void
    {
        $this->recount($args->getObjectManager(), $track->getArtist());
    }

    private function recount(EntityManagerInterface $em, Artist $artist): void
    {
        $count = $this->artists->recountTracks($artist->getId());
        $artist->syncTrackCount($count);
        // Record it as the persisted value so the next flush does not write it back.
        $em->getUnitOfWork()->setOriginalEntityProperty(spl_object_id($artist), 'trackCount', $count);
    }
}
