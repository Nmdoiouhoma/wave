<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\State\ProcessorInterface;
use App\Activity\ActivityJournal;
use App\ApiResource\ArtistView;
use App\Artist\ArtistCreator;
use App\Artist\ArtistLookup;
use App\Dto\Input\CreateArtistInput;
use App\Entity\Artist;
use App\Enum\ActivityType;
use App\Enum\ReportTargetType;
use App\Http\JsonInputDecoder;
use App\Mapper\ArtistMapper;
use App\Moderation\ContentDeleter;
use App\Problem\ApiProblemException;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

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
        private EntityManagerInterface $em,
        private ArtistMapper $mapper,
        private ContentDeleter $deleter,
        private ActivityJournal $journal,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ArtistView
    {
        $viewer = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];

        if ($operation instanceof DeleteOperationInterface) {
            $artist = $this->lookup->owned($uriVariables['slug'] ?? null);
            $this->em->wrapInTransaction(fn () => $this->deleter->deleteArtist($artist));

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
        $this->journal->record(ActivityType::ArtistCreated, $viewer, $viewer, ReportTargetType::Artist, $artist->getId());

        return $this->mapper->toView($artist, $viewer);
    }

    private static function cleanBio(?string $bio): ?string
    {
        $bio = null === $bio ? null : trim($bio);

        return '' === $bio ? null : $bio;
    }
}
