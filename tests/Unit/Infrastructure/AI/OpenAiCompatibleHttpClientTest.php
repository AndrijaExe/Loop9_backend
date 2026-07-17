<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\OpenAiCompatibleHttpClient;
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
                'message' => str_repeat('secret player prompt echo ', 20),
            ],
            'choices' => [
                ['message' => ['content' => 'should not appear']],
            ],
        ]);

        self::assertSame('invalid_request_error', $summary['type'] ?? null);
        self::assertSame('context_length_exceeded', $summary['code'] ?? null);
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
}
