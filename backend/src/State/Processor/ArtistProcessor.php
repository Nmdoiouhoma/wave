<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ArtistView;
use App\Artist\ArtistCreator;
use App\Artist\ArtistLookup;
use App\Dto\Input\CreateArtistInput;
use App\Entity\Artist;
use App\Http\JsonInputDecoder;
use App\Mapper\ArtistMapper;
use App\Message\PurgeObjects;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\TrackRepository;
use App\Security\CurrentUser;
use App\Storage\StorageArea;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `POST /artists`, `PATCH|DELETE /artists/{slug}`.
 *
 * @implements ProcessorInterface<mixed, ArtistView|null>
 */
final readonly class ArtistProcessor implements ProcessorInterface
{
    public function __construct(
        private JsonInputDecoder $decoder,
        private CurrentUser $currentUser,
        private ArtistLookup $lookup,
        private ArtistCreator $creator,
        private TrackRepository $tracks,
        private EntityManagerInterface $em,
        private ArtistMapper $mapper,
        private MessageBusInterface $bus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ArtistView
    {
        $viewer = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];

        if ($operation instanceof DeleteOperationInterface) {
            $artist = $this->lookup->owned($uriVariables['slug'] ?? null);
            $this->delete($artist);

            return null;
        }

        $input = $this->decoder->decode($request, CreateArtistInput::class);
        $input->rejectExplicitNull('name');

        if ($operation instanceof Patch) {
            $artist = $this->lookup->owned($uriVariables['slug'] ?? null);
            if ($input->has('name')) {
                $artist->setName(trim((string) $input->name));
            }
            if ($input->has('bio')) {
                $artist->setBio(self::cleanBio($input->bio));
            }
            $this->em->flush();

            return $this->mapper->toView($artist, $viewer);
        }

        if (!$input->has('name')) {
            throw ApiProblemException::validation('name', 'This value should not be blank.');
        }
        $artist = $this->em->wrapInTransaction(fn (): Artist => $this->creator->create($viewer, trim((string) $input->name), self::cleanBio($input->bio)));

        return $this->mapper->toView($artist, $viewer);
    }

    private function delete(Artist $artist): void
    {
        $this->em->wrapInTransaction(function () use ($artist): void {
            // Any track, whatever its status (FK RESTRICT is the backstop).
            if ($this->tracks->countByArtist($artist) > 0) {
                throw new ApiProblemException(ProblemCode::ArtistHasTracks, 'Delete or move the tracks of this artist first.');
            }
            $avatar = $artist->getAvatarKey();
            $this->em->remove($artist);
            $this->em->flush();
            if (null !== $avatar) {
                $this->bus->dispatch(new PurgeObjects(StorageArea::Public->value, [$avatar]));
            }
        });
    }

    private static function cleanBio(?string $bio): ?string
    {
        $bio = null === $bio ? null : trim($bio);

        return '' === $bio ? null : $bio;
    }
}
