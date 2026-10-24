<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TrackView;
use App\Mapper\TrackMapper;
use App\Message\ProcessImage;
use App\Security\CurrentUser;
use App\State\Provider\TrackLookup;
use App\Upload\ImageUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST|DELETE /tracks/{trackId}/cover` (owner only).
 *
 * @implements ProcessorInterface<mixed, TrackView|null>
 */
final readonly class TrackCoverProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private TrackLookup $lookup,
        private ImageUploader $images,
        private EntityManagerInterface $em,
        private TrackMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?TrackView
    {
        $viewer = $this->currentUser->require();
        $track = $this->lookup->owned($uriVariables['trackId'] ?? null);

        if ($operation instanceof DeleteOperationInterface) {
            $old = $track->getCoverKey();
            $this->em->wrapInTransaction(function () use ($track, $old): void {
                $track->setCoverKey(null);
                $this->em->flush();
                $this->images->purge($old);
            });

            return null;
        }

        /** @var Request $request */
        $request = $context['request'];
        $this->images->accept($request, ProcessImage::TARGET_TRACK_COVER, $track->getId());

        return $this->mapper->toView($track, $viewer);
    }
}
