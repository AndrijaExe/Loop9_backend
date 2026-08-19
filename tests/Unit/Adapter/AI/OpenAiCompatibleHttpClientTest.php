<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\AI;

use App\Adapter\AI\OpenAiCompatibleHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OpenAiCompatibleHttpClientTest extends TestCase
{
    public function testSummarizeErrorPayloadRedactsFullBodies(): void
    {
        $client = new OpenAiCompatibleHttpClient(
            $this->createStub(HttpClientInterface::class),
            new NullLogger(),
        );

        $summary = $client->summarizeErrorPayload([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'context_length_exceeded',
                'param' => 'temperature',
                'message' => str_repeat('secret player prompt echo ', 20),
            ],
            'choices' => [
                ['message' => ['content' => 'should not appear']],
            ],
        ]);

        self::assertSame('invalid_request_error', $summary['type'] ?? null);
        self::assertSame('context_length_exceeded', $summary['code'] ?? null);
        self::assertSame('temperature', $summary['param'] ?? null);
        self::assertArrayNotHasKey('message', $summary);
        $encodedSummary = json_encode($summary, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret player prompt echo', $encodedSummary);
        self::assertStringNotContainsString('should not appear', $encodedSummary);
    }

    public function testSummarizeErrorPayloadDropsUntrustedIdentifiers(): void
    {
        $client = new OpenAiCompatibleHttpClient(
            $this->createStub(HttpClientInterface::class),
            new NullLogger(),
        );

        self::assertSame([], $client->summarizeErrorPayload([
            'error' => [
                'type' => "invalid\nforged-log-line",
                'code' => str_repeat('x', 65),
            ],
        ]));
    }

    public function testOpenAiPayloadOmitsCustomTemperature(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.openai.com/v1/chat/completions', $url);
            $payload = json_decode((string) $options['body'], true, 16, JSON_THROW_ON_ERROR);
            self::assertArrayNotHasKey('temperature', $payload);
            self::assertSame(90, $payload['max_completion_tokens'] ?? null);
            self::assertSame('none', $payload['reasoning_effort'] ?? null);
            self::assertArrayNotHasKey('max_tokens', $payload);

            return $this->validCompletionResponse();
        });

        $client = new OpenAiCompatibleHttpClient($http, new NullLogger());
        $response = $client->chatCompletion(
            [
                'label' => 'fallback1',
                'url' => 'https://api.openai.com/v1/chat/completions',
                'apiKey' => 'key',
                'model' => 'gpt-5.6-luna',
                'verifyTls' => true,
            ],
            [['role' => 'user', 'content' => 'hi']],
            90,
        );

        self::assertSame(200, $response['statusCode']);
        self::assertSame(10, $response['promptTokens']);
        self::assertSame(5, $response['completionTokens']);
        self::assertSame(8, $response['cachedTokens']);
    }

    public function testGroqPayloadKeepsSamplingAndCompatibleTokenField(): void
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.groq.com/openai/v1/chat/completions', $url);
            $payload = json_decode((string) $options['body'], true, 16, JSON_THROW_ON_ERROR);
            self::assertSame(0.7, $payload['temperature'] ?? null);
            self::assertSame(90, $payload['max_tokens'] ?? null);
            self::assertArrayNotHasKey('max_completion_tokens', $payload);

            return $this->validCompletionResponse();
        });

        $client = new OpenAiCompatibleHttpClient($http, new NullLogger());
        $response = $client->chatCompletion(
            [
                'label' => 'fallback2',
                'url' => 'https://api.groq.com/openai/v1/chat/completions',
                'apiKey' => 'key',
                'model' => 'llama-3.3-70b-versatile',
                'verifyTls' => true,
            ],
            [['role' => 'user', 'content' => 'hi']],
            90,
        );

        self::assertSame(200, $response['statusCode']);
    }

    public function testRejectsExhaustedTimeoutBudget(): void
    {
        $client = new OpenAiCompatibleHttpClient(
            $this->createStub(HttpClientInterface::class),
            new NullLogger(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('budget exhausted');

        $client->chatCompletion(
            [
                'label' => 'primary',
                'url' => 'https://example.test/v1/chat/completions',
                'apiKey' => 'key',
                'model' => 'model',
                'verifyTls' => true,
            ],
            [['role' => 'user', 'content' => 'hi']],
            64,
            0.1,
        );
    }

    public function testRejectsProviderResponseOverByteLimit(): void
    {
        $http = new MockHttpClient(new MockResponse(
            str_repeat('x', OpenAiCompatibleHttpClient::MAX_RESPONSE_BYTES + 1),
            ['http_code' => 200],
        ));
        $client = new OpenAiCompatibleHttpClient($http, new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('body exceeds');

        $client->chatCompletion(
            [
                'label' => 'primary',
                'url' => 'https://example.test/v1/chat/completions',
                'apiKey' => 'key',
                'model' => 'model',
                'verifyTls' => true,
            ],
            [['role' => 'user', 'content' => 'hi']],
            64,
        );
    }

    private function validCompletionResponse(): MockResponse
    {
        return new MockResponse(json_encode([
            'choices' => [[
                'message' => [
                    'content' => 'Reply.[STATE]KINDNESS=0;SUSPICION=0',
                ],
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 5,
                'prompt_tokens_details' => [
                    'cached_tokens' => 8,
                ],
            ],
        ], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }
}
