<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Adapter\Configuration\ProductionConfigValidator;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Readiness probe: configuration + rate-limiter storage.
 * Keep /healthz as a pure liveness check so Redis blips do not restart the instance.
 */
final class ReadyController
{
    public function __construct(
        private readonly ProductionConfigValidator $configValidator,
        #[Autowire(service: 'rate_limiter.storage')]
        private readonly CacheItemPoolInterface $rateLimiterStorage,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/readyz', name: 'ready', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $issues = $this->configValidator->issues();

        try {
            $item = $this->rateLimiterStorage->getItem('readyz_probe');
            $item->set(1);
            $item->expiresAfter(5);
            if (!$this->rateLimiterStorage->save($item)) {
                $issues[] = 'Rate-limiter storage rejected write.';
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Readiness rate-limiter storage probe failed.', [
                'exceptionClass' => $exception::class,
            ]);
            $issues[] = 'Rate-limiter storage unavailable.';
        }

        if ($issues !== []) {
            $this->logger->warning('Readiness checks failed.', [
                'issues' => $issues,
            ]);

            return new JsonResponse(['status' => 'not_ready'], 503);
        }

        return new JsonResponse(['status' => 'ready']);
    }
}
