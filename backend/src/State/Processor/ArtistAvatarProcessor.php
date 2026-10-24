<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ArtistView;
use App\Artist\ArtistLookup;
use App\Mapper\ArtistMapper;
use App\Message\ProcessImage;
use App\Security\CurrentUser;
use App\Upload\ImageUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST|DELETE /artists/{slug}/avatar` (owner only).
 *
 * @implements ProcessorInterface<mixed, ArtistView|null>
 */
final readonly class ArtistAvatarProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ArtistLookup $lookup,
        private ImageUploader $images,
        private EntityManagerInterface $em,
        private ArtistMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?ArtistView
    {
        $viewer = $this->currentUser->require();
        $artist = $this->lookup->owned($uriVariables['slug'] ?? null);

        if ($operation instanceof DeleteOperationInterface) {
            $old = $artist->getAvatarKey();
            $this->em->wrapInTransaction(function () use ($artist, $old): void {
                $artist->setAvatarKey(null);
                $this->em->flush();
                $this->images->purge($old);
            });

            return null;
        }

        /** @var Request $request */
        $request = $context['request'];
        $this->images->accept($request, ProcessImage::TARGET_ARTIST_AVATAR, $artist->getId());

        return $this->mapper->toView($artist, $viewer);
    }
}
