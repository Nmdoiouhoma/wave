<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\MeView;
use App\Dto\Input\UpdateMeInput;
use App\Http\JsonInputDecoder;
use App\Mapper\UserMapper;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/** @implements ProcessorInterface<mixed, MeView> */
final readonly class UpdateMeProcessor implements ProcessorInterface
{
    public function __construct(
        private JsonInputDecoder $decoder,
        private CurrentUser $currentUser,
        private EntityManagerInterface $em,
        private UserMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MeView
    {
        $user = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, UpdateMeInput::class);
        $input->rejectExplicitNull('displayName');

        if ($input->has('displayName')) {
            $user->setDisplayName(trim((string) $input->displayName));
        }
        if ($input->has('bio')) {
            $bio = null === $input->bio ? null : trim($input->bio);
            $user->setBio('' === $bio ? null : $bio);
        }
        $this->em->flush();

        return $this->mapper->toMe($user);
    }
}
