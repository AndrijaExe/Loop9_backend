<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

interface OpenAiCompatibleHttpClientInterface
{
    /**
     * @param list<array{role: string, content: string}> $messages
     * @param array{label: string, url: string, apiKey: string, model: string, verifyTls: bool, tier?: string} $provider
     * @return array{statusCode: int, data: array<string, mixed>, latencyMs: float, promptTokens: ?int, completionTokens: ?int}
     */
    public function chatCompletion(array $provider, array $messages, int $maxTokens, float $timeoutSeconds = OpenAiCompatibleHttpClient::DEFAULT_TIMEOUT_SECONDS): array;

    /**
     * @param array<string, mixed> $data
     */
    public function extractContent(array $data): string;

    /**
     * @param array<string, mixed> $data
     * @return array{type?: string, code?: string}
     */
    public function summarizeErrorPayload(array $data): array;
}
