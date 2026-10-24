<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Dto\Input\LoginInput;
use App\Dto\Input\RegisterInput;
use App\Entity\User;
use App\Http\JsonInputDecoder;
use App\Message\SendVerificationEmail;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\UserRepository;
use App\Security\SessionIssuer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/** register / login / refresh / logout (openapi tag `auth`). Rate limits: App\RateLimit\RateLimitListener. */
final readonly class SessionController
{
    public function __construct(
        private JsonInputDecoder $decoder,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private SessionIssuer $sessions,
        private MessageBusInterface $bus,
    ) {
    }

    #[Route('/api/v1/auth/register', name: 'register', methods: ['POST'])]
    public function register(Request $request): Response
    {
        $input = $this->decoder->decode($request, RegisterInput::class);
        $email = mb_strtolower(trim((string) $input->email));
        $username = (string) $input->username;

        if ($this->users->emailExists($email)) {
            throw new ApiProblemException(ProblemCode::EmailTaken, 'This email is already registered.');
        }
        if ($this->users->usernameExists($username)) {
            throw new ApiProblemException(ProblemCode::UsernameTaken, 'This username is already taken.');
        }

        $user = new User($email, $username, trim((string) $input->displayName));
        $user->setPassword($this->hasher->hashPassword($user, (string) $input->password));

        try {
            $this->em->wrapInTransaction(function () use ($user): void {
                $this->em->persist($user);
                $this->em->flush();
                $this->bus->dispatch(new SendVerificationEmail($user->getId()->toRfc4122()));
            });
        } catch (UniqueConstraintViolationException $e) {
            // Concurrent registration with the same email/username.
            $code = str_contains($e->getMessage(), 'uniq_app_user_username') ? ProblemCode::UsernameTaken : ProblemCode::EmailTaken;

            throw new ApiProblemException($code, null, [], [], $e);
        }

        return $this->sessions->open($user, $request, Response::HTTP_CREATED);
    }

    #[Route('/api/v1/auth/login', name: 'login', methods: ['POST'])]
    public function login(Request $request): Response
    {
        try {
            $input = $this->decoder->decode($request, LoginInput::class);
        } catch (ApiProblemException $e) {
            // LoginRequest has no 422: a body without email/password is malformed.
            if (ProblemCode::ValidationFailed === $e->problemCode) {
                throw new ApiProblemException(ProblemCode::MalformedRequest, 'email and password are required.');
            }
            throw $e;
        }

        $user = $this->users->findOneByEmail(trim((string) $input->email));
        $password = (string) $input->password;
        if (null === $user) {
            // Same cost as a real check: response time does not reveal unknown emails.
            $this->hasher->hashPassword(new User('nobody@invalid', 'nobody', 'nobody'), $password);

            throw new ApiProblemException(ProblemCode::InvalidCredentials, 'Invalid email or password.');
        }
        if (!$this->hasher->isPasswordValid($user, $password)) {
            throw new ApiProblemException(ProblemCode::InvalidCredentials, 'Invalid email or password.');
        }
        if ($user->isSuspended()) {
            throw new ApiProblemException(ProblemCode::AccountSuspended);
        }
        if ($this->hasher->needsRehash($user)) {
            $user->setPassword($this->hasher->hashPassword($user, $password));
            $this->em->flush();
        }

        return $this->sessions->open($user, $request);
    }

    #[Route('/api/v1/auth/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(Request $request): Response
    {
        return $this->sessions->rotate($request);
    }

    #[Route('/api/v1/auth/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        return $this->sessions->close($request);
    }
}
