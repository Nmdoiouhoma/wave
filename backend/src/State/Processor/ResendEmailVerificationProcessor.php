<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Message\SendVerificationEmail;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Security\CurrentUser;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * `POST /me/email-verification` (202).
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class ResendEmailVerificationProcessor implements ProcessorInterface
{
    public function __construct(private CurrentUser $currentUser, private MessageBusInterface $bus)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = $this->currentUser->require();
        if ($user->isEmailVerified()) {
            throw new ApiProblemException(ProblemCode::EmailAlreadyVerified);
        }
        $this->bus->dispatch(new SendVerificationEmail($user->getId()->toRfc4122()));

        return null;
    }
}
