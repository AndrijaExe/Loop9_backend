<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Anonymous end-of-run telemetry for balancing (which endings players reach,
 * how many resets, how much they talk to the AI).
 *
 * Storage is deliberately log-based: entries land in the structured app log
 * ("Run telemetry.") and can be aggregated from the hosting platform's log
 * stream. No database, no personal data.
 */
final class RunTelemetryController
{
    private const ALLOWED_ENDINGS = [
        'escape_together',
        'obedient_fool',
        'cold_betrayal',
        'merged_memory',
        'the_replacement',
        'paranoid_survivor',
    ];

    private const MAX_COUNTER = 100000;
    private const MAX_BUILD_LENGTH = 64;

    public function __construct(
        private readonly GameTokenAuthenticator $tokenAuthenticator,
        #[Autowire(service: 'limiter.telemetry_ip')]
        private readonly RateLimiterFactory $telemetryLimiterFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/telemetry/run', name: 'telemetry_run', methods: ['POST', 'OPTIONS'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        $this->tokenAuthenticator->authenticate($request);

        $ip = $request->getClientIp() ?? 'unknown';
        $rateLimit = $this->telemetryLimiterFactory->create(hash('sha256', 'telemetry|' . $ip))->consume(1);

        if (!$rateLimit->isAccepted()) {
            return new JsonResponse([
                'error' => ['message' => 'Too many telemetry requests.', 'code' => 'RATE_LIMITED'],
            ], 429);
        }

        $payload = $this->parsePayload($request);

        $this->logger->info('Run telemetry.', [
            'ending' => $payload['ending'],
            'resets' => $payload['resets'],
            'aiMessages' => $payload['ai_messages'],
            'build' => $payload['build'],
        ]);

        return new JsonResponse(null, 204);
    }

    /**
     * @return array{ending: string, resets: int, ai_messages: int, build: ?string}
     */
    private function parsePayload(Request $request): array
    {
        try {
            $data = json_decode($request->getContent(), true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Request body must be valid JSON.');
        }

        if (!is_array($data)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }

        $ending = $data['ending'] ?? null;

        if (!is_string($ending) || !in_array($ending, self::ALLOWED_ENDINGS, true)) {
            throw new BadRequestHttpException('Field "ending" must be a known ending id.');
        }

        $build = $data['build'] ?? null;

        if ($build !== null && (!is_string($build) || mb_strlen($build) > self::MAX_BUILD_LENGTH)) {
            $build = null;
        }

        return [
            'ending' => $ending,
            'resets' => $this->clampCounter($data['resets'] ?? 0),
            'ai_messages' => $this->clampCounter($data['ai_messages'] ?? 0),
            'build' => $build,
        ];
    }

    private function clampCounter(mixed $value): int
    {
        if (!is_int($value)) {
            return 0;
        }

        return max(0, min(self::MAX_COUNTER, $value));
    }
}
