<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness probe for the hosting platform (Render health check path).
 * Deliberately does not touch Redis or AI providers: a degraded dependency
 * should surface as request errors, not as a full instance restart loop.
 */
final class HealthController
{
    #[Route('/healthz', name: 'health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
