<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Account\AccountTokenIssuer;
use App\Dto\Input\AccountTokenInput;
use App\Dto\Input\PasswordResetConfirmInput;
use App\Dto\Input\PasswordResetInput;
use App\Enum\AccountTokenPurpose;
use App\Http\JsonInputDecoder;
use App\Message\SendPasswordResetEmail;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Security\SessionIssuer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Email verification and password reset (ADR 007). */
final readonly class AccountTokenController
{
    public function __construct(
        private JsonInputDecoder $decoder,
        private AccountTokenIssuer $tokens,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private UserPasswordHasherInterface $hasher,
        private SessionIssuer $sessions,
    ) {
    }

    #[Route('/api/v1/auth/verify-email', name: 'verifyEmail', methods: ['POST'])]
    public function verifyEmail(Request $request): Response
    {
        $input = $this->decodeToken($request, AccountTokenInput::class);

        $this->em->wrapInTransaction(function () use ($input): void {
            $user = $this->tokens->consume((string) $input->token, AccountTokenPurpose::EmailVerification);
            $user->markEmailVerified();
            $this->em->flush();
        });

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Anti-enumeration: identical 202 with an empty body whatever the email; the lookup
     * happens in the worker (SendPasswordResetEmailHandler).
     */
    #[Route('/api/v1/auth/password-reset', name: 'requestPasswordReset', methods: ['POST'])]
    public function requestPasswordReset(Request $request): Response
    {
        $input = $this->decoder->decode($request, PasswordResetInput::class);
        $this->bus->dispatch(new SendPasswordResetEmail(mb_strtolower(trim((string) $input->email))));

        return new Response(null, Response::HTTP_ACCEPTED);
    }

    #[Route('/api/v1/auth/password-reset/confirm', name: 'confirmPasswordReset', methods: ['POST'])]
    public function confirmPasswordReset(Request $request): Response
    {
        $input = $this->decodeToken($request, PasswordResetConfirmInput::class);

        $this->em->wrapInTransaction(function () use ($input): void {
            $user = $this->tokens->consume((string) $input->token, AccountTokenPurpose::PasswordReset);
            $user->setPassword($this->hasher->hashPassword($user, (string) $input->newPassword));
            $user->markEmailVerified();
            $this->em->flush();
            $this->sessions->revokeAll($user);
        });

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * A missing/oversized token is a bad token (400), not a validation error; other
     * fields (newPassword) keep their 422.
     *
     * @template T of AccountTokenInput|PasswordResetConfirmInput
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function decodeToken(Request $request, string $class): AccountTokenInput|PasswordResetConfirmInput
    {
        try {
            return $this->decoder->decode($request, $class);
        } catch (ApiProblemException $e) {
            foreach ($e->violations as $violation) {
                if ('token' === $violation->propertyPath) {
                    throw new ApiProblemException(ProblemCode::AccountTokenInvalid, 'This link is invalid or has expired.', [], [], $e);
                }
            }
            throw $e;
        }
    }
}
