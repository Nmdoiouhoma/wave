<?php

declare(strict_types=1);

namespace App\RateLimit;

use App\Entity\User;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Route (= operationId) → limiters, from the `x-rate-limit` annotations of openapi.yaml.
 * Runs after the firewall (priority 8) so that per-user limits see the authenticated user.
 * Every listed limiter is consumed on every request; the first exhausted one answers 429.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
final readonly class RateLimitListener
{
    private const string BY_IP = 'ip';
    private const string BY_IP_EMAIL = 'ip_email';
    private const string BY_EMAIL = 'email';
    private const string BY_USER = 'user';

    /** @var array<string, list<array{string, string}>> */
    private const array ROUTES = [
        'register' => [['register_ip', self::BY_IP]],
        'login' => [['login_ip_email', self::BY_IP_EMAIL], ['login_ip', self::BY_IP]],
        'refresh' => [['refresh_ip', self::BY_IP]],
        'verifyEmail' => [['account_token_verify_ip', self::BY_IP]],
        'confirmPasswordReset' => [['account_token_reset_ip', self::BY_IP]],
        'requestPasswordReset' => [['password_reset_email', self::BY_EMAIL], ['password_reset_ip', self::BY_IP]],
        'resendEmailVerification' => [['verification_resend_user', self::BY_USER]],
        'changePassword' => [['password_change_user', self::BY_USER]],
        'uploadMyAvatar' => [['image_upload_user', self::BY_USER]],
        'uploadArtistAvatar' => [['image_upload_user', self::BY_USER]],
        'uploadTrackCover' => [['image_upload_user', self::BY_USER]],
        'uploadTrack' => [['track_upload_hour_user', self::BY_USER], ['track_upload_day_user', self::BY_USER]],
        'getTrackStreamUrl' => [['stream_url_user', self::BY_USER]],
    ];

    /** @param ServiceLocator<RateLimiterFactoryInterface> $limiters */
    public function __construct(
        #[AutowireLocator('rate_limiter', indexAttribute: 'name')]
        private ServiceLocator $limiters,
        private Security $security,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        if (!\is_string($route) || !isset(self::ROUTES[$route])) {
            return;
        }

        foreach (self::ROUTES[$route] as [$name, $by]) {
            $key = $this->key($request, $by);
            if (null === $key) {
                continue;
            }
            $limit = $this->limiters->get($name)->create($key)->consume();
            if (!$limit->isAccepted()) {
                $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());

                throw new ApiProblemException(ProblemCode::RateLimited, 'Too many requests, retry later.', [], ['Retry-After' => (string) $retryAfter]);
            }
        }
    }

    private function key(Request $request, string $by): ?string
    {
        $ip = $request->getClientIp() ?? 'unknown';

        return match ($by) {
            self::BY_IP => $ip,
            self::BY_IP_EMAIL => $ip.'|'.($this->email($request) ?? ''),
            self::BY_EMAIL => $this->email($request),
            self::BY_USER => ($user = $this->security->getUser()) instanceof User ? $user->getId()->toRfc4122() : null,
            default => null,
        };
    }

    /** Normalized (trimmed, lowercased) `email` of a JSON body, if any. */
    private function email(Request $request): ?string
    {
        try {
            $data = json_decode($request->getContent(), true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $email = \is_array($data) ? ($data['email'] ?? null) : null;

        return \is_string($email) && '' !== trim($email) ? mb_strtolower(trim($email)) : null;
    }
}
