<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\MeView;
use App\Mapper\UserMapper;
use App\Message\ProcessImage;
use App\Security\CurrentUser;
use App\Upload\ImageUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST|DELETE /me/avatar`.
 *
 * @implements ProcessorInterface<mixed, MeView|null>
 */
final readonly class MyAvatarProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private ImageUploader $images,
        private EntityManagerInterface $em,
        private UserMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?MeView
    {
        $user = $this->currentUser->require();

        if ($operation instanceof DeleteOperationInterface) {
            $old = $user->getAvatarKey();
            $this->em->wrapInTransaction(function () use ($user, $old): void {
                $user->setAvatarKey(null);
                $this->em->flush();
                $this->images->purge($old);
            });

            return null;
        }

        /** @var Request $request */
        $request = $context['request'];
        $this->images->accept($request, ProcessImage::TARGET_USER_AVATAR, $user->getId());

        return $this->mapper->toMe($user);
    }
}
