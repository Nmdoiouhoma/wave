<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PlaylistView;
use App\Dto\Input\PlaylistInput;
use App\Entity\Playlist;
use App\Http\JsonInputDecoder;
use App\Mapper\PlaylistMapper;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Security\CurrentUser;
use App\State\Provider\PlaylistLookup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /playlists`, `PATCH|DELETE /playlists/{playlistId}` (owner only).
 *
 * @implements ProcessorInterface<mixed, PlaylistView|null>
 */
final readonly class PlaylistProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private PlaylistLookup $lookup,
        private EntityManagerInterface $em,
        private PlaylistMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?PlaylistView
    {
        $viewer = $this->currentUser->require();

        if ($operation instanceof DeleteOperationInterface) {
            $playlist = $this->lookup->owned($uriVariables['playlistId'] ?? null);
            // Items and likes go by FK cascade; reports on it are removed with W-33.
            $this->em->remove($playlist);
            $this->em->flush();

            return null;
        }

        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, PlaylistInput::class);
        $input->rejectExplicitNull('name', 'isPublic');

        if ($operation instanceof Patch) {
            $playlist = $this->lookup->owned($uriVariables['playlistId'] ?? null);
            if ($input->has('name')) {
                $playlist->setName(trim((string) $input->name));
            }
            if ($input->has('description')) {
                $playlist->setDescription(self::clean($input->description));
            }
            if (null !== $input->isPublic) {
                $playlist->setPublic($input->isPublic);
            }
            $this->em->flush();

            return $this->mapper->toView($playlist, $viewer);
        }

        if (!$input->has('name')) {
            throw ApiProblemException::validation('name', 'This value should not be blank.');
        }
        $playlist = $this->em->wrapInTransaction(function () use ($viewer, $input): Playlist {
            // W-26 F15: limit per owner, concurrent creations serialised by a row lock on the user.
            $conn = $this->em->getConnection();
            $conn->executeQuery('SELECT 1 FROM app_user WHERE id = :id FOR UPDATE', ['id' => $viewer->getId()->toRfc4122()]);
            if ((int) $conn->fetchOne('SELECT count(*) FROM playlist WHERE owner_id = :id', ['id' => $viewer->getId()->toRfc4122()]) >= Playlist::MAX_PER_USER) {
                throw new ApiProblemException(ProblemCode::PlaylistLimitReached, \sprintf('A user can have at most %d playlists.', Playlist::MAX_PER_USER));
            }
            $playlist = new Playlist($viewer, trim((string) $input->name), self::clean($input->description), $input->isPublic ?? true);
            $this->em->persist($playlist);
            $this->em->flush();

            return $playlist;
        });

        return $this->mapper->toView($playlist, $viewer);
    }

    private static function clean(?string $text): ?string
    {
        $text = null === $text ? null : trim($text);

        return '' === $text ? null : $text;
    }
}
