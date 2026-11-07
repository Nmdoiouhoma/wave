<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Put;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PlaylistItemView;
use App\Dto\Input\AddPlaylistItemInput;
use App\Dto\Input\ReorderPlaylistInput;
use App\Entity\Playlist;
use App\Enum\MediaStatus;
use App\Http\JsonInputDecoder;
use App\Mapper\DateFormat;
use App\Mapper\TrackMapper;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\PlaylistRepository;
use App\Security\CurrentUser;
use App\State\Provider\PlaylistLookup;
use App\State\Provider\TrackLookup;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Playlist items (owner only, DATA_MODEL §7): append, remove + compact, full reorder.
 * Every write locks the playlist row first, so concurrent edits keep positions contiguous.
 *
 * @implements ProcessorInterface<mixed, PlaylistItemView|null>
 */
final readonly class PlaylistItemProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private PlaylistLookup $playlistLookup,
        private TrackLookup $trackLookup,
        private PlaylistRepository $playlists,
        private TrackMapper $trackMapper,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?PlaylistItemView
    {
        $viewer = $this->currentUser->require();
        $playlist = $this->playlistLookup->owned($uriVariables['playlistId'] ?? null);
        /** @var Request $request */
        $request = $context['request'];

        if ($operation instanceof DeleteOperationInterface) {
            $trackId = $uriVariables['trackId'] ?? null;
            if (!\is_string($trackId) || !Uuid::isValid($trackId)) {
                throw ApiProblemException::notFound();
            }
            $removed = $this->em->wrapInTransaction(function () use ($playlist, $trackId): bool {
                $this->playlists->lock($playlist);

                return $this->playlists->remove($playlist, Uuid::fromString($trackId));
            });
            if (!$removed) {
                throw ApiProblemException::notFound('This track is not in the playlist.');
            }

            return null;
        }

        if ($operation instanceof Put) {
            $input = $this->decoder->decode($request, ReorderPlaylistInput::class);
            $this->reorder($playlist, array_map(static fn (mixed $id): string => strtolower((string) $id), $input->trackIds ?? []));

            return null;
        }

        $input = $this->decoder->decode($request, AddPlaylistItemInput::class);
        $track = $this->trackLookup->visible($input->trackId);
        if (MediaStatus::Ready !== $track->getStatus()) {
            throw new ApiProblemException(ProblemCode::TrackNotReady, 'Only ready tracks can be added.');
        }
        try {
            $added = $this->em->wrapInTransaction(function () use ($playlist, $track): array {
                // Track before playlist, the order of a track deletion (F17).
                if (!$this->playlists->shareTrack($track->getId())) {
                    throw ApiProblemException::notFound();
                }
                if ($this->playlists->lock($playlist) >= Playlist::MAX_ITEMS) {
                    throw new ApiProblemException(ProblemCode::PlaylistFull, \sprintf('A playlist holds at most %d tracks.', Playlist::MAX_ITEMS));
                }
                if ($this->playlists->contains($playlist, $track->getId())) {
                    throw new ApiProblemException(ProblemCode::TrackAlreadyInPlaylist, 'This track is already in the playlist.');
                }

                return $this->playlists->append($playlist, $track->getId());
            });
        } catch (ForeignKeyConstraintViolationException) {
            throw ApiProblemException::notFound(); // track deleted meanwhile (F17, residual)
        }

        return new PlaylistItemView(
            $track->getId()->toRfc4122(),
            $added['position'],
            DateFormat::format(new \DateTimeImmutable($added['addedAt'])),
            $this->trackMapper->toView($track, $viewer),
        );
    }

    /** @param list<string> $order */
    private function reorder(Playlist $playlist, array $order): void
    {
        $this->em->wrapInTransaction(function () use ($playlist, $order): void {
            $this->playlists->lock($playlist);
            $current = $this->playlists->trackIds($playlist);
            $sortedOrder = $order;
            $sortedCurrent = $current;
            sort($sortedOrder);
            sort($sortedCurrent);
            // Same multiset: catches missing, extra and duplicated ids alike.
            if ($sortedOrder !== $sortedCurrent) {
                throw new ApiProblemException(ProblemCode::PlaylistOrderMismatch, 'Send every track of the playlist exactly once.');
            }
            $this->playlists->reorder($playlist, $order);
        });
    }
}
