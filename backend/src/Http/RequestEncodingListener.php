<?php

declare(strict_types=1);

namespace App\Http;

use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * F12: PostgreSQL text cannot hold NUL (pdo_pgsql truncates at it) nor invalid UTF-8
 * (SQLSTATE 22021 → 500). Path and query string are checked here, before routing and the
 * firewall, and answer 400 `malformed_request`; JSON bodies are checked by {@see JsonInputDecoder}.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 256)]
final readonly class RequestEncodingListener
{
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!self::isCleanText(rawurldecode($request->getPathInfo())) || !self::isClean($request->query->all())) {
            throw new ApiProblemException(ProblemCode::MalformedRequest, 'The URL contains a NUL byte or invalid UTF-8.');
        }
    }

    /** True when every string (keys included) is valid UTF-8 without NUL. */
    public static function isClean(mixed $value): bool
    {
        if (\is_string($value)) {
            return self::isCleanText($value);
        }
        if (\is_array($value)) {
            foreach ($value as $key => $item) {
                if (!self::isCleanText((string) $key) || !self::isClean($item)) {
                    return false;
                }
            }
        }

        return true;
    }

    public static function isCleanText(string $text): bool
    {
        return !str_contains($text, "\0") && mb_check_encoding($text, 'UTF-8');
    }
}
