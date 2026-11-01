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
        $playlist = new Playlist($viewer, trim((string) $input->name), self::clean($input->description), $input->isPublic ?? true);
        $this->em->persist($playlist);
        $this->em->flush();

        return $this->mapper->toView($playlist, $viewer);
    }

    private static function clean(?string $text): ?string
    {
        $text = null === $text ? null : trim($text);

        return '' === $text ? null : $text;
    }
}
