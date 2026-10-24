<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * - `X-Frame-Options` / `X-Content-Type-Options` are set by nginx for every response; the
 *   copies API Platform adds are removed so that each header appears once.
 * - Authenticated API answers must never be stored by a cache (stream URLs, profiles…).
 * Operations that opt into caching (e.g. `GET /genres`) declare `public` and are kept.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -32)]
final class ResponseHeadersListener
{
    /** Request attribute a processor sets to emit a `Location` header (e.g. 202 upload). */
    public const string LOCATION_ATTRIBUTE = '_wave_location';

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }
        $response = $event->getResponse();
        $response->headers->remove('X-Frame-Options');
        $response->headers->remove('X-Content-Type-Options');
        $location = $event->getRequest()->attributes->get(self::LOCATION_ATTRIBUTE);
        if (\is_string($location) && $response->isSuccessful()) {
            $response->headers->set('Location', $location);
        }
        if ($response->headers->hasCacheControlDirective('public') || $response->headers->has('X-Accel-Redirect')) {
            return;
        }
        $response->headers->set('Cache-Control', 'no-store, private');
    }
}
