<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class CorsSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onResponse',
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();

        if (!str_starts_with((string) $request->getPathInfo(), '/api/')) {
            return;
        }

        $origin = (string) $request->headers->get('Origin', '*');
        $response = $event->getResponse();

        if ($origin !== '' && $origin !== 'null' && $this->isLocalOrigin($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        } else {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Game-Token, X-Player-Id');
        $response->headers->set('Access-Control-Max-Age', '600');

        if ($request->isMethod(Request::METHOD_OPTIONS)) {
            $response->setStatusCode(204);
        }
    }

    private function isLocalOrigin(string $origin): bool
    {
        $parsed = parse_url($origin);

        if (!is_array($parsed)) {
            return false;
        }

        $host = (string) ($parsed['host'] ?? '');

        return $host === 'localhost' || $host === '127.0.0.1';
    }
}
