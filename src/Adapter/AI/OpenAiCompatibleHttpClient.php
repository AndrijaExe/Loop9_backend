<?php

declare(strict_types=1);

namespace App\Adapter\AI;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAI-compatible chat completions HTTP transport.
 */
final class OpenAiCompatibleHttpClient implements OpenAiCompatibleHttpClientInterface
{
    public const DEFAULT_TIMEOUT_SECONDS = 30.0;
    public const MAX_RESPONSE_BYTES = 262_144;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array{label: string, url: string, apiKey: string, model: string, verifyTls: bool, tier?: string} $provider
     * @return array{statusCode: int, data: array<string, mixed>, latencyMs: float, promptTokens: ?int, completionTokens: ?int}
     */
    public function chatCompletion(array $provider, array $messages, int $maxTokens, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): array
    {
        if ($timeoutSeconds <= 0.5) {
            throw new \RuntimeException('AI request budget exhausted.');
        }

        $payload = [
            'model' => $provider['model'],
            'messages' => $messages,
        ];

        if (str_contains($provider['url'], 'api.openai.com')) {
            $payload['max_completion_tokens'] = $maxTokens;
            $payload['reasoning_effort'] = 'none';
        } else {
            $payload['temperature'] = 0.7;
            $payload['max_tokens'] = $maxTokens;
        }

        $startedAt = microtime(true);

        $response = $this->httpClient->request('POST', $provider['url'], [
            'headers' => [
                'Authorization' => 'Bearer ' . $provider['apiKey'],
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'timeout' => $timeoutSeconds,
            'max_duration' => $timeoutSeconds,
            'max_redirects' => 0,
            'buffer' => false,
            'verify_peer' => $provider['verifyTls'],
            'verify_host' => $provider['verifyTls'],
        ]);

        $statusCode = $response->getStatusCode();
        $body = '';
        foreach ($this->httpClient->stream($response) as $chunk) {
            $content = $chunk->getContent();
            if (strlen($body) + strlen($content) > self::MAX_RESPONSE_BYTES) {
                $response->cancel();
                throw new \RuntimeException(sprintf(
                    'AI provider returned invalid response: body exceeds %d bytes.',
                    self::MAX_RESPONSE_BYTES,
                ));
            }

            $body .= $content;
        }

        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            if ($statusCode === 200) {
                throw new \RuntimeException('AI provider returned invalid response.', previous: $exception);
            }

            $decoded = [];
        }

        if (!is_array($decoded)) {
            if ($statusCode === 200) {
                throw new \RuntimeException('AI provider returned invalid response.');
            }

            $decoded = [];
        }

        /** @var array<string, mixed> $data */
        $data = $decoded;
        $latencyMs = (microtime(true) - $startedAt) * 1000;

        return [
            'statusCode' => $statusCode,
            'data' => $data,
            'latencyMs' => round($latencyMs, 2),
            'promptTokens' => $this->extractPromptTokens($data),
            'completionTokens' => $this->extractCompletionTokens($data),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function extractContent(array $data): string
    {
        $content = $data['choices'][0]['message']['content'] ?? null;

        if (!is_string($content) || trim($content) === '') {
            $this->logger->warning('AI provider returned invalid payload.', [
                'keys' => array_keys($data),
                'hasChoices' => isset($data['choices']),
            ]);

            throw new \RuntimeException('AI provider returned invalid response.');
        }

        return $content;
    }

    /**
     * Safe summary of provider error payloads for logs (no prompt/content echo).
     *
     * @param array<string, mixed> $data
     * @return array{type?: string, code?: string, param?: string}
     */
    public function summarizeErrorPayload(array $data): array
    {
        $error = $data['error'] ?? null;
        if (!is_array($error)) {
            return [];
        }

        $summary = [];
        foreach (['type', 'code', 'param'] as $field) {
            if (isset($error[$field]) && is_scalar($error[$field])) {
                $value = (string) $error[$field];
                if (preg_match('/\A[A-Za-z0-9_.:-]{1,64}\z/', $value) === 1) {
                    $summary[$field] = $value;
                }
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractPromptTokens(array $data): ?int
    {
        if (isset($data['usage']) && is_array($data['usage'])
            && isset($data['usage']['prompt_tokens']) && is_numeric($data['usage']['prompt_tokens'])) {
            return (int) $data['usage']['prompt_tokens'];
        }

        if (isset($data['usageMetadata']) && is_array($data['usageMetadata'])
            && isset($data['usageMetadata']['promptTokenCount']) && is_numeric($data['usageMetadata']['promptTokenCount'])) {
            return (int) $data['usageMetadata']['promptTokenCount'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractCompletionTokens(array $data): ?int
    {
        if (isset($data['usage']) && is_array($data['usage'])
            && isset($data['usage']['completion_tokens']) && is_numeric($data['usage']['completion_tokens'])) {
            return (int) $data['usage']['completion_tokens'];
        }

        if (isset($data['usageMetadata']) && is_array($data['usageMetadata'])
            && isset($data['usageMetadata']['candidatesTokenCount']) && is_numeric($data['usageMetadata']['candidatesTokenCount'])) {
            return (int) $data['usageMetadata']['candidatesTokenCount'];
        }

        return null;
    }
}
