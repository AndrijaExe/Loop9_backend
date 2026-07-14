<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenAI-compatible chat completions HTTP transport.
 */
final class OpenAiCompatibleHttpClient
{
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
    public function chatCompletion(array $provider, array $messages, int $maxTokens): array
    {
        $payload = [
            'model' => $provider['model'],
            'messages' => $messages,
            'temperature' => 0.7,
        ];

        if (str_contains($provider['url'], 'api.openai.com')) {
            $payload['max_completion_tokens'] = $maxTokens;
        } else {
            $payload['max_tokens'] = $maxTokens;
        }

        $startedAt = microtime(true);

        $response = $this->httpClient->request('POST', $provider['url'], [
            'headers' => [
                'Authorization' => 'Bearer ' . $provider['apiKey'],
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
            'timeout' => 30,
            'verify_peer' => $provider['verifyTls'],
            'verify_host' => $provider['verifyTls'],
        ]);

        $statusCode = $response->getStatusCode();
        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);
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
                'response' => $data,
            ]);

            throw new \RuntimeException('AI provider returned invalid response.');
        }

        return $content;
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
