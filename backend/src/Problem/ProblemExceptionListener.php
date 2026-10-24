<?php

declare(strict_types=1);

namespace App\Problem;

use App\Security\EmailVerifiedVoter;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Validator\Exception\ValidationFailedException;

/**
 * Renders every exception as RFC 7807 problem+json (CONVENTIONS §5).
 *
 * Runs after the security exception listener (priority 1) and the logger (0),
 * before Symfony/API Platform error listeners (-128), which it short-circuits.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -64)]
final readonly class ProblemExceptionListener
{
    public function __construct(
        private ProblemResponseFactory $factory,
        private LoggerInterface $logger,
        #[Autowire('%kernel.debug%')]
        private bool $debug,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
        $event->allowCustomResponseCode();
        $event->setResponse($this->toResponse($e));
    }

    private function toResponse(\Throwable $e): \Symfony\Component\HttpFoundation\JsonResponse
    {
        $problem = $this->find($e, ApiProblemException::class);
        if (null !== $problem) {
            return $this->factory->fromException($problem);
        }

        $validation = $this->find($e, ValidationFailedException::class);
        if (null !== $validation) {
            return $this->factory->fromException(ApiProblemException::fromViolations($validation->getViolations()));
        }

        $denied = $this->find($e, AccessDeniedException::class);
        if (null !== $denied) {
            $code = EmailVerifiedVoter::DENIAL_MESSAGE === $denied->getMessage()
                || \in_array(EmailVerifiedVoter::ATTRIBUTE, $denied->getAttributes(), true)
                ? ProblemCode::EmailNotVerified
                : ProblemCode::Forbidden;

            return $this->factory->create($code);
        }

        if (null !== $this->find($e, AuthenticationException::class)) {
            return $this->factory->create(ProblemCode::Unauthenticated);
        }

        if ($e instanceof TooManyRequestsHttpException) {
            return $this->factory->create(ProblemCode::RateLimited, null, [], $this->stringHeaders($e->getHeaders()));
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            // No 405 code in the contract: a method that is not routed is an unknown resource.
            return $this->factory->create(ProblemCode::NotFound);
        }

        if ($e instanceof BadRequestException) {
            return $this->factory->create(ProblemCode::MalformedRequest);
        }

        if ($e instanceof HttpExceptionInterface) {
            $code = match ($e->getStatusCode()) {
                400 => ProblemCode::MalformedRequest,
                401 => ProblemCode::Unauthenticated,
                403 => ProblemCode::Forbidden,
                404, 405 => ProblemCode::NotFound,
                413 => ProblemCode::PayloadTooLarge,
                415 => ProblemCode::UnsupportedMediaType,
                422 => ProblemCode::ValidationFailed,
                429 => ProblemCode::RateLimited,
                default => null,
            };
            if (null !== $code) {
                return $this->factory->create($code, null, [], ProblemCode::RateLimited === $code ? $this->stringHeaders($e->getHeaders()) : []);
            }
        }

        $this->logger->error('Unhandled exception: {class}: {message}', ['class' => $e::class, 'message' => $e->getMessage(), 'exception' => $e]);

        return $this->factory->create(ProblemCode::InternalError, $this->debug ? $e::class.': '.$e->getMessage() : null);
    }

    /**
     * @template T of \Throwable
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function find(\Throwable $e, string $class): ?\Throwable
    {
        for ($current = $e; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return $current;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    private function stringHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            if (\is_scalar($value)) {
                $out[$name] = (string) $value;
            }
        }

        return $out;
    }
}
