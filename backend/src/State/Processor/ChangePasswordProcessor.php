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
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * `POST /me/password` (ADR 008): revokes every other session and rotates the current one;
 * answered like `/auth/refresh` (cookie on web, body on mobile).
 *
 * @implements ProcessorInterface<mixed, Response>
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

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Response
    {
        $user = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, ChangePasswordInput::class);

        if (!$this->hasher->isPasswordValid($user, (string) $input->currentPassword)) {
            throw ApiProblemException::validation('currentPassword', 'The current password is incorrect.');
        }

        $hash = $this->hasher->hashPassword($user, (string) $input->newPassword);

        return $this->em->wrapInTransaction(function () use ($user, $hash, $request): Response {
            $user->setPassword($hash);
            $this->em->flush();

            return $this->sessions->keepOnlyCurrent($user, $request);
        });
    }
}
