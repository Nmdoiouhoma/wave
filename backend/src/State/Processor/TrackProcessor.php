<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TrackView;
use App\Dto\Input\UpdateTrackInput;
use App\Entity\Genre;
use App\Entity\Track;
use App\Http\JsonInputDecoder;
use App\Mapper\TrackMapper;
use App\Message\PurgeObjects;
use App\Problem\ApiProblemException;
use App\Repository\ArtistRepository;
use App\Repository\GenreRepository;
use App\Security\CurrentUser;
use App\State\Provider\TrackLookup;
use App\Storage\StorageArea;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `PATCH|DELETE /tracks/{trackId}` (owner only).
 *
 * @implements ProcessorInterface<mixed, TrackView|null>
 */
final readonly class TrackProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private TrackLookup $lookup,
        private JsonInputDecoder $decoder,
        private GenreRepository $genres,
        private ArtistRepository $artists,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private TrackMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?TrackView
    {
        $viewer = $this->currentUser->require();
        $track = $this->lookup->owned($uriVariables['trackId'] ?? null);

        if ($operation instanceof DeleteOperationInterface) {
            $this->delete($track);

            return null;
        }

        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, UpdateTrackInput::class);
        $input->rejectExplicitNull('title', 'genreId', 'artistId', 'visibility', 'allowClips');

        if ($input->has('genreId')) {
            $genre = $this->genres->find((string) $input->genreId);
            if (!$genre instanceof Genre) {
                throw ApiProblemException::validation('genreId', 'Unknown genre.');
            }
            $track->setGenre($genre);
        }
        if ($input->has('artistId')) {
            $artist = $this->artists->find(Uuid::fromString((string) $input->artistId));
            if (null === $artist || !$artist->isOwnedBy($viewer)) {
                throw ApiProblemException::notFound('Artist not found.');
            }
            $track->moveToArtist($artist);
        }
        if ($input->has('title')) {
            $track->setTitle(trim((string) $input->title));
        }
        if (null !== $input->visibility) {
            $track->setVisibility($input->visibility);
        }
        if (null !== $input->allowClips) {
            $track->setAllowClips($input->allowClips);
        }
        $this->em->flush();

        return $this->mapper->toView($track, $viewer);
    }

    /**
     * DATA_MODEL §0 deletion order: fix counters the cascade would leave stale, collect
     * keys and purge them asynchronously, delete polymorphic reports, then delete.
     * Phase 1 has no likes/playlists/posts/reports yet: W-20/W-22/W-30/W-33 extend this.
     */
    private function delete(Track $track): void
    {
        $private = array_values(array_filter([$track->getRawKey(), $track->getAudioKey()]));
        $public = null === $track->getCoverKey() ? [] : [$track->getCoverKey()];

        $this->em->wrapInTransaction(function () use ($track, $private, $public): void {
            $this->em->remove($track);
            $this->em->flush();
            foreach ([StorageArea::Private->value => $private, StorageArea::Public->value => $public] as $area => $keys) {
                foreach (array_chunk($keys, PurgeObjects::MAX_KEYS) as $chunk) {
                    $this->bus->dispatch(new PurgeObjects($area, $chunk));
                }
            }
        });
    }
}
