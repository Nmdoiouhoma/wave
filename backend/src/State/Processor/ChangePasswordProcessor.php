<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Input\ChangePasswordInput;
use App\Http\JsonInputDecoder;
use App\Problem\ApiProblemException;
use App\Security\CurrentUser;
use App\Security\SessionIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `POST /me/password`: revokes every refresh token of the user.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class ChangePasswordProcessor implements ProcessorInterface
{
    public function __construct(
        private JsonInputDecoder $decoder,
        private CurrentUser $currentUser,
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface $em,
        private SessionIssuer $sessions,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, ChangePasswordInput::class);

        if (!$this->hasher->isPasswordValid($user, (string) $input->currentPassword)) {
            throw ApiProblemException::validation('currentPassword', 'The current password is incorrect.');
        }

        $this->em->wrapInTransaction(function () use ($user, $input): void {
            $user->setPassword($this->hasher->hashPassword($user, (string) $input->newPassword));
            $this->em->flush();
            $this->sessions->revokeAll($user);
        });

        return null;
    }
}
