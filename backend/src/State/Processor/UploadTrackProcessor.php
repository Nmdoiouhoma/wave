<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TrackView;
use App\Artist\ArtistCreator;
use App\Dto\Input\UploadTrackInput;
use App\Entity\Artist;
use App\Entity\Genre;
use App\Entity\Track;
use App\Entity\User;
use App\Http\CacheControlListener;
use App\Http\JsonInputDecoder;
use App\Mapper\TrackMapper;
use App\Message\ProcessTrack;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\ArtistRepository;
use App\Repository\GenreRepository;
use App\Security\CurrentUser;
use App\Upload\QuarantineWriter;
use App\Upload\UploadedFileValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /tracks` (multipart, 202): size/type pre-check, quarantine, Track `processing`,
 * ProcessTrack dispatched in the same transaction (CONVENTIONS §2). Artist resolution:
 * given → must be owned (404); none owned → auto-created from displayName; exactly one →
 * that one; several → 422 artist_required.
 *
 * @implements ProcessorInterface<mixed, TrackView>
 */
final readonly class UploadTrackProcessor implements ProcessorInterface
{
    private const array FIELDS = ['title', 'genreId', 'artistId', 'visibility', 'allowClips'];

    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private GenreRepository $genres,
        private ArtistRepository $artists,
        private ArtistCreator $artistCreator,
        private QuarantineWriter $quarantine,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private TrackMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TrackView
    {
        $user = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];

        $file = UploadedFileValidator::require($request, 'file', Track::MAX_SIZE_BYTES, UploadedFileValidator::AUDIO_TYPES);
        $input = $this->decoder->hydrate($this->fields($request), UploadTrackInput::class);

        $genre = $this->genres->find((string) $input->genreId);
        if (!$genre instanceof Genre) {
            throw ApiProblemException::validation('genreId', 'Unknown genre.');
        }

        $trackId = Uuid::v7();
        $rawKey = 'quarantine/tracks/'.$trackId->toRfc4122().'/source';
        // Object first: if the transaction fails, the orphan is swept by CleanupQuarantine.
        $this->quarantine->write($file, $rawKey);

        $track = $this->em->wrapInTransaction(function () use ($user, $input, $genre, $trackId, $rawKey, $file): Track {
            $artist = $this->resolveArtist($user, $input->artistId);
            $track = new Track($artist, $genre, trim((string) $input->title), (int) $file->getSize(), $input->visibility, $input->allowClips, $trackId);
            $track->setRawKey($rawKey);
            $this->em->persist($track);
            $this->em->flush();
            $this->bus->dispatch(new ProcessTrack($trackId->toRfc4122()));

            return $track;
        });

        $request->attributes->set(CacheControlListener::LOCATION_ATTRIBUTE, '/api/v1/tracks/'.$trackId->toRfc4122());

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

    /**
     * Multipart text fields → typed values for the input DTO; unknown fields are ignored.
     *
     * @return array<string, mixed>
     */
    private function fields(Request $request): array
    {
        $fields = [];
        foreach (self::FIELDS as $name) {
            if (!$request->request->has($name)) {
                continue;
            }
            $value = $request->request->all()[$name];
            if ('allowClips' === $name && \is_string($value)) {
                $value = match (strtolower($value)) {
                    'true', '1' => true,
                    'false', '0' => false,
                    default => $value,
                };
            }
            if ('artistId' === $name && '' === $value) {
                continue;
            }
            $fields[$name] = $value;
        }

        return $fields;
    }
}
