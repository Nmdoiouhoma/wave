<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TrackView;
use App\Artist\ArtistCreator;
use App\Dto\Input\CreateTrackInput;
use App\Entity\Artist;
use App\Entity\Genre;
use App\Entity\Track;
use App\Entity\TrackUpload;
use App\Entity\User;
use App\Enum\TrackUploadStatus;
use App\Http\JsonInputDecoder;
use App\Http\ResponseHeadersListener;
use App\Mapper\TrackMapper;
use App\Message\ProcessTrack;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\ArtistRepository;
use App\Repository\GenreRepository;
use App\Repository\TrackUploadRepository;
use App\Security\CurrentUser;
use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /tracks` (ADR 009 step 2, JSON): the upload must be the caller's (404), unused
 * (409 upload_already_used), younger than 2 h (409 upload_expired) and present in storage
 * (409 upload_incomplete). The Track (`processing`, real object size), the atomic consume
 * and ProcessTrack commit together. Artist resolution: given → must be owned (404); none
 * owned → auto-created from displayName; exactly one → that one; several → 422 artist_required.
 *
 * @implements ProcessorInterface<mixed, TrackView>
 */
final readonly class CreateTrackProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private GenreRepository $genres,
        private ArtistRepository $artists,
        private ArtistCreator $artistCreator,
        private TrackUploadRepository $uploads,
        private ObjectStorage $storage,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private TrackMapper $mapper,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TrackView
    {
        $user = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, CreateTrackInput::class);

        $genre = $this->genres->find((string) $input->genreId);
        if (!$genre instanceof Genre) {
            throw ApiProblemException::validation('genreId', 'Unknown genre.');
        }

        $upload = $this->uploads->find(Uuid::fromString((string) $input->uploadId));
        if (!$upload instanceof TrackUpload || !$upload->getUser()->getId()->equals($user->getId())) {
            throw ApiProblemException::notFound('Upload not found.');
        }
        $now = $this->clock->now();
        if (TrackUploadStatus::Consumed === $upload->getStatus()) {
            throw new ApiProblemException(ProblemCode::UploadAlreadyUsed, 'This upload was already used.');
        }
        if (TrackUploadStatus::Expired === $upload->getStatus() || $upload->getCreatedAt() <= $now->sub(new \DateInterval(TrackUpload::CONSUME_WINDOW))) {
            throw new ApiProblemException(ProblemCode::UploadExpired, 'This upload has expired, start a new one.');
        }
        $key = $upload->getQuarantineKey();
        if (!$this->storage->exists(StorageArea::Private, $key)) {
            throw new ApiProblemException(ProblemCode::UploadIncomplete, 'The file has not been received yet.');
        }
        $size = $this->storage->size(StorageArea::Private, $key);

        $track = $this->em->wrapInTransaction(function () use ($user, $input, $genre, $upload, $key, $size, $now): Track {
            $artist = $this->resolveArtist($user, $input->artistId);
            $track = new Track($artist, $genre, trim((string) $input->title), min($size, Track::MAX_SIZE_BYTES), $input->visibility, $input->allowClips);
            $track->setRawKey($key);
            $this->em->persist($track);
            $this->em->flush();
            if (!$this->uploads->consume($upload->getId(), $user, $track->getId(), $now)) {
                // Lost a race with a concurrent consume (or crossed the 2 h window): roll back.
                throw new ApiProblemException(ProblemCode::UploadAlreadyUsed, 'This upload was already used.');
            }
            $this->bus->dispatch(new ProcessTrack($track->getId()->toRfc4122()));

            return $track;
        });

        $request->attributes->set(ResponseHeadersListener::LOCATION_ATTRIBUTE, '/api/v1/tracks/'.$track->getId()->toRfc4122());

        return $this->mapper->toView($track, $user);
    }

    private function resolveArtist(User $user, ?string $artistId): Artist
    {
        if (null !== $artistId) {
            $artist = $this->artists->find(Uuid::fromString($artistId));
            if (null === $artist || !$artist->isOwnedBy($user)) {
                throw ApiProblemException::notFound('Artist not found.');
            }

            return $artist;
        }
        $owned = $this->artists->findByOwner($user, includeHidden: true);

        return match (\count($owned)) {
            0 => $this->artistCreator->create($user, $user->getDisplayName()),
            1 => $owned[0],
            default => throw new ApiProblemException(ProblemCode::ArtistRequired, 'Choose the artist of this track (artistId).'),
        };
    }
}
