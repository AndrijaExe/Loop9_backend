<?php

declare(strict_types=1);

namespace App\Adapter\AI;

use App\Model\Chat\ContentSafetyDecision;
use App\Model\Chat\ContentSafetyGateway;
use App\Model\Chat\LocalSafetyDetector;
use App\Model\Telemetry\Event;
use App\Model\Telemetry\EventCounters;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiContentSafetyGateway implements ContentSafetyGateway
{
    public const MAX_RESPONSE_BYTES = 65_536;

    /**
     * omni-moderation-latest's complete category schema. Requiring every
     * category prevents a truncated or provider-incompatible response from
     * being interpreted as safe.
     */
    private const REQUIRED_CATEGORIES = [
        'harassment',
        'harassment/threatening',
        'hate',
        'hate/threatening',
        'illicit',
        'illicit/violent',
        'self-harm',
        'self-harm/intent',
        'self-harm/instructions',
        'sexual',
        'sexual/minors',
        'violence',
        'violence/graphic',
    ];

    /**
     * Steam live-AI + OpenAI/Groq: block illegal / AO sexual, not gameplay tone.
     * Insults, in-fiction threats, and horror violence must reach the model
     * so KINDNESS/SUSPICION can move.
     */
    private const BLOCK_BOTH_STAGES = [
        'sexual/minors',
        'sexual',
        'self-harm/intent',
        'self-harm/instructions',
        'illicit',
        'illicit/violent',
    ];

    /**
     * Steam distribution rules + OpenAI: the game must not generate
     * group-targeted hate. Player insults toward Dragojlo stay allowed on input.
     */
    private const BLOCK_OUTPUT_ONLY = [
        'hate',
        'hate/threatening',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LocalSafetyDetector $localDetector,
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        private readonly EventCounters $counters,
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
            return $this->unavailable($stage, ['configuration'], 0.0);
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
                'max_duration' => max(1, $this->timeoutSeconds),
                'max_redirects' => 0,
                'buffer' => false,
            ]);

            if ($response->getStatusCode() !== 200) {
                return $this->unavailable($stage, ['provider_status'], $startedAt);
            }

            $body = '';
            foreach ($this->httpClient->stream($response) as $chunk) {
                $content = $chunk->getContent();
                if (strlen($body) + strlen($content) > self::MAX_RESPONSE_BYTES) {
                    $response->cancel();
                    throw new \RuntimeException('Moderation response exceeds the allowed size.');
                }

                $body .= $content;
            }

            $data = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            $result = is_array($data) ? ($data['results'][0] ?? null) : null;
            if (!is_array($data)
                || !isset($data['results'])
                || !is_array($data['results'])
                || count($data['results']) !== 1
                || !is_array($result)
                || !is_bool($result['flagged'] ?? null)
                || !$this->hasCompleteCategorySchema($result['categories'] ?? null)) {
                return $this->unavailable($stage, ['invalid_response'], $startedAt);
            }

            $categories = $this->flaggedCategories($result['categories'] ?? null);
            $blocking = $this->blockingCategories($categories, $stage);
            if ($blocking !== []) {
                $this->logDecision($stage, 'blocked', $blocking, $startedAt);

                return ContentSafetyDecision::blocked($blocking[0]);
            }

            if ($result['flagged'] && $categories === []) {
                $this->logDecision($stage, 'blocked', ['provider_flagged'], $startedAt);

                return ContentSafetyDecision::blocked('provider_flagged');
            }

            $this->logDecision($stage, 'allowed', $categories, $startedAt);

            return ContentSafetyDecision::safe();
        } catch (\Throwable $exception) {
            $this->logger->warning('Content moderation request unavailable.', [
                'requestId' => $this->requestId(),
                'stage' => $stage,
                'exceptionClass' => $exception::class,
            ]);

            return $this->unavailable($stage, ['exception'], $startedAt);
        }
    }

    /**
     * Input fails open so a dead moderation key cannot freeze the coworker.
     * Output fails closed so unchecked model text is not shown.
     *
     * @param list<string> $reasons
     */
    private function unavailable(string $stage, array $reasons, int|float $startedAt): ContentSafetyDecision
    {
        $this->logDecision($stage, 'unavailable', $reasons, $startedAt);

        if ($stage === self::STAGE_INPUT) {
            return ContentSafetyDecision::safe();
        }

        return ContentSafetyDecision::blocked('moderation_unavailable');
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
     * @return list<string>
     */
    private function blockingCategories(array $categories, string $stage): array
    {
        $blocked = self::BLOCK_BOTH_STAGES;
        if ($stage === self::STAGE_OUTPUT) {
            $blocked = array_merge($blocked, self::BLOCK_OUTPUT_ONLY);
        }

        return array_values(array_filter(
            $categories,
            static fn (string $category): bool => in_array($category, $blocked, true)
        ));
    }

    private function hasCompleteCategorySchema(mixed $rawCategories): bool
    {
        if (!is_array($rawCategories)) {
            return false;
        }

        foreach (self::REQUIRED_CATEGORIES as $category) {
            if (!array_key_exists($category, $rawCategories) || !is_bool($rawCategories[$category])) {
                return false;
            }
        }

        foreach ($rawCategories as $name => $value) {
            if (!is_string($name)
                || preg_match('/\A[a-z0-9_\/-]{1,64}\z/i', $name) !== 1
                || !is_bool($value)) {
                return false;
            }
        }

        return true;
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

        $counted = match ($verdict) {
            'blocked' => Event::SAFETY_BLOCKED,
            'unavailable' => Event::SAFETY_UNAVAILABLE,
            default => null,
        };

        if ($counted !== null) {
            $this->counters->increment($counted);
        }
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
