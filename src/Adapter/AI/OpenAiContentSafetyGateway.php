<?php

declare(strict_types=1);

namespace App\Adapter\AI;

use App\Model\Chat\ContentSafetyDecision;
use App\Model\Chat\ContentSafetyGateway;
use App\Model\Chat\LocalSafetyDetector;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiContentSafetyGateway implements ContentSafetyGateway
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LocalSafetyDetector $localDetector,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        #[Autowire(env: 'AI_MODERATION_URL')]
        private readonly string $url,
        #[Autowire(env: 'AI_MODERATION_API_KEY')]
        private readonly string $apiKey,
        #[Autowire(env: 'AI_FALLBACK_API_KEY')]
        private readonly string $fallbackApiKey,
        #[Autowire(env: 'AI_MODERATION_MODEL')]
        private readonly string $model,
        #[Autowire(env: 'int:AI_MODERATION_TIMEOUT_SECONDS')]
        private readonly int $timeoutSeconds,
    ) {
    }

    public function evaluate(string $text, string $stage): ContentSafetyDecision
    {
        if (!in_array($stage, [self::STAGE_INPUT, self::STAGE_OUTPUT], true)) {
            throw new \InvalidArgumentException('Unknown content-safety stage.');
        }

        if (($reason = $this->localDetector->detect($text, $stage)) !== null) {
            $this->logDecision($stage, 'blocked', [$reason], 0.0);

            return ContentSafetyDecision::blocked($reason);
        }

        $apiKey = trim($this->apiKey) !== '' ? trim($this->apiKey) : trim($this->fallbackApiKey);
        if ($apiKey === '' || !$this->isHttpsUrl($this->url) || trim($this->model) === '') {
            $this->logDecision($stage, 'unavailable', ['configuration'], 0.0);

            return ContentSafetyDecision::blocked('moderation_unavailable');
        }

        $startedAt = hrtime(true);

        try {
            $response = $this->httpClient->request('POST', $this->url, [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => $this->model,
                    'input' => $text,
                ],
                'timeout' => max(1, $this->timeoutSeconds),
            ]);

            if ($response->getStatusCode() !== 200) {
                $this->logDecision($stage, 'unavailable', ['provider_status'], $startedAt);

                return ContentSafetyDecision::blocked('moderation_unavailable');
            }

            $data = $response->toArray(false);
            $result = $data['results'][0] ?? null;
            if (!is_array($result) || !is_bool($result['flagged'] ?? null)) {
                $this->logDecision($stage, 'unavailable', ['invalid_response'], $startedAt);

                return ContentSafetyDecision::blocked('moderation_unavailable');
            }

            $categories = $this->flaggedCategories($result['categories'] ?? null);
            if ($result['flagged'] || $categories !== []) {
                $this->logDecision($stage, 'blocked', $categories !== [] ? $categories : ['provider_flagged'], $startedAt);

                return ContentSafetyDecision::blocked($categories[0] ?? 'provider_flagged');
            }

            $this->logDecision($stage, 'allowed', [], $startedAt);

            return ContentSafetyDecision::safe();
        } catch (\Throwable $exception) {
            $this->logger->warning('Content moderation request unavailable.', [
                'requestId' => $this->requestId(),
                'stage' => $stage,
                'exceptionClass' => $exception::class,
            ]);

            return ContentSafetyDecision::blocked('moderation_unavailable');
        }
    }

    /**
     * @return list<string>
     */
    private function flaggedCategories(mixed $rawCategories): array
    {
        if (!is_array($rawCategories)) {
            return [];
        }

        $flagged = [];
        foreach ($rawCategories as $name => $value) {
            if ($value === true && is_string($name) && preg_match('/\A[a-z0-9_\/-]{1,64}\z/i', $name) === 1) {
                $flagged[] = $name;
            }
        }

        return array_slice($flagged, 0, 16);
    }

    /**
     * @param list<string> $categories
     */
    private function logDecision(string $stage, string $verdict, array $categories, int|float $startedAt): void
    {
        $latencyMs = $startedAt === 0.0
            ? 0.0
            : round((hrtime(true) - (int) $startedAt) / 1_000_000, 2);

        $this->logger->info('Content safety decision.', [
            'requestId' => $this->requestId(),
            'stage' => $stage,
            'verdict' => $verdict,
            'categories' => $categories,
            'latencyMs' => $latencyMs,
            'provider' => 'openai',
        ]);
    }

    private function requestId(): string
    {
        $requestId = (string) $this->requestStack->getCurrentRequest()?->headers->get('X-Request-Id', '');

        return preg_match('/\A[A-Fa-f0-9-]{16,64}\z/', $requestId) === 1
            ? $requestId
            : 'unknown';
    }

    private function isHttpsUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== '';
    }
}
