<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Activity\ActivityJournal;
use App\ApiResource\TrackView;
use App\Dto\Input\UpdateTrackInput;
use App\Entity\Genre;
use App\Enum\ActivityType;
use App\Enum\ReportTargetType;
use App\Http\JsonInputDecoder;
use App\Mapper\TrackMapper;
use App\Moderation\ContentDeleter;
use App\Problem\ApiProblemException;
use App\Repository\ArtistRepository;
use App\Repository\GenreRepository;
use App\Security\CurrentUser;
use App\State\Provider\TrackLookup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
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
        private TrackMapper $mapper,
        private ContentDeleter $deleter,
        private ActivityJournal $journal,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?TrackView
    {
        $viewer = $this->currentUser->require();
        $track = $this->lookup->owned($uriVariables['trackId'] ?? null);

        if ($operation instanceof DeleteOperationInterface) {
            $trackId = $track->getId();
            $ownerId = $track->getOwner()->getId();
            $this->em->wrapInTransaction(fn () => $this->deleter->deleteTrack($track));
            $this->journal->record(ActivityType::TrackDeleted, $viewer, $ownerId, ReportTargetType::Track, $trackId);

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
}
