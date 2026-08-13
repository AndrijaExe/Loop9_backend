<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Model\Telemetry\CountersUnavailable;
use App\Model\Telemetry\EventCounters;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Cumulative event counts for whoever is watching this service.
 *
 * Read-only and pull-based on purpose. The alternative — this service pushing to a monitor on
 * a timer — would mean adding a scheduler and holding the monitor's credentials, to answer a
 * question the monitor is already awake to ask.
 */
final class MetricsController
{
    public function __construct(
        private readonly EventCounters $counters,
        #[Autowire('%env(METRICS_TOKEN)%')]
        private readonly string $token,
    ) {
    }

    #[Route('/metrics', name: 'metrics', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        // Unconfigured means the endpoint does not exist, rather than exists and is shut.
        if (trim($this->token) === '') {
            throw new NotFoundHttpException();
        }

        $offered = (string) $request->headers->get('X-Metrics-Token', '');
        if (!hash_equals($this->token, $offered)) {
            throw new AccessDeniedHttpException('Metrics token required.');
        }

        try {
            $counters = $this->counters->totals();
        } catch (CountersUnavailable) {
            return new JsonResponse([
                'error' => ['message' => 'Counter storage unavailable.', 'code' => 'COUNTERS_UNAVAILABLE'],
            ], 503);
        }

        return new JsonResponse([
            'counters' => (object) $counters,
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
    }
}
