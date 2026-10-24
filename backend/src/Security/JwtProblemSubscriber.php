<?php

declare(strict_types=1);

namespace App\Security;

use App\Problem\ProblemCode;
use App\Problem\ProblemResponseFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Maps LexikJWT failures to problem+json: expired → token_expired (the client refreshes),
 * suspended → account_suspended, anything else → unauthenticated.
 */
final readonly class JwtProblemSubscriber implements EventSubscriberInterface
{
    public function __construct(private ProblemResponseFactory $factory)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::JWT_NOT_FOUND => 'onFailure',
            Events::JWT_INVALID => 'onFailure',
            Events::JWT_EXPIRED => 'onExpired',
            Events::AUTHENTICATION_FAILURE => 'onFailure',
        ];
    }

    public function onExpired(AuthenticationFailureEvent $event): void
    {
        $event->setResponse($this->factory->create(ProblemCode::TokenExpired));
    }

    public function onFailure(AuthenticationFailureEvent $event): void
    {
        for ($e = $event->getException(); null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof AccountSuspendedException) {
                $event->setResponse($this->factory->create(ProblemCode::AccountSuspended));

                return;
            }
        }
        $event->setResponse($this->factory->create(ProblemCode::Unauthenticated));
    }
}
