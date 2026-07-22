<?php

declare(strict_types=1);

namespace App\Adapter\Http\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Reject oversized JSON bodies before controllers call getContent().
 * Complements Apache LimitRequestBody for non-Apache / test clients.
 */
final class RequestBodyLimitSubscriber implements EventSubscriberInterface
{
    public const MAX_BODY_BYTES = 65536;

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 32],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('POST') && !$request->isMethod('PUT') && !$request->isMethod('PATCH')) {
            return;
        }

        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/')) {
            return;
        }

        $contentLength = $request->headers->get('Content-Length');
        if ($contentLength !== null && ctype_digit($contentLength) && (int) $contentLength > self::MAX_BODY_BYTES) {
            throw new HttpException(413, sprintf(
                'Request body must be at most %d bytes.',
                self::MAX_BODY_BYTES
            ));
        }

        // Content-Length may be absent (chunked). Bound after read for API routes.
        $body = $request->getContent();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new HttpException(413, sprintf(
                'Request body must be at most %d bytes.',
                self::MAX_BODY_BYTES
            ));
        }
    }
}
