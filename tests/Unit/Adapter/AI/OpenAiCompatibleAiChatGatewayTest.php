<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\AI;

use App\Model\Chat\ProviderRoutingPolicy;
use App\Model\Chat\RuntimeContext;
use App\Model\Chat\AssistantReplyFormatValidator;
use App\Adapter\AI\OpenAiCompatibleAiChatGateway;
use App\Adapter\AI\AiProviderCatalog;
use App\Adapter\AI\CostEstimator;
use App\Adapter\AI\OpenAiCompatibleHttpClientInterface;
use App\Adapter\AI\PromptFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;

final class OpenAiCompatibleAiChatGatewayTest extends TestCase
{
    public function testTriesExactlyOneFallbackAfterInvalidFormat(): void
    {
        $http = $this->createMock(OpenAiCompatibleHttpClientInterface::class);
        $http->expects(self::exactly(2))
            ->method('chatCompletion')
            ->willReturnOnConsecutiveCalls(
                [
                    'statusCode' => 200,
                    'data' => [],
                    'latencyMs' => 12.0,
                    'promptTokens' => 10,
                    'completionTokens' => 5,
                ],
                [
                    'statusCode' => 200,
                    'data' => [],
                    'latencyMs' => 14.0,
                    'promptTokens' => 10,
                    'completionTokens' => 8,
                ],
            );
        $http->method('extractContent')->willReturnOnConsecutiveCalls(
            'bad reply without state',
            'Recovered.[STATE]KINDNESS=0;SUSPICION=0',
        );
        $http->expects(self::never())->method('summarizeErrorPayload');

        $gateway = $this->makeGateway($http, $this->catalogWithFallback());

        self::assertSame(
            'Recovered.[STATE]KINDNESS=0;SUSPICION=0',
            $gateway->ask('hello', new RuntimeContext(loopIndex: 1))->content(),
        );
    }

    public function testStopsAfterSecondInvalidFormat(): void
    {
        $http = $this->createMock(OpenAiCompatibleHttpClientInterface::class);
        $http->expects(self::exactly(2))
            ->method('chatCompletion')
            ->willReturn([
                'statusCode' => 200,
                'data' => [],
                'latencyMs' => 12.0,
                'promptTokens' => 10,
                'completionTokens' => 5,
            ]);
        $http->method('extractContent')->willReturn('bad reply without state');

        $gateway = $this->makeGateway($http, $this->catalogWithFallback());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid reply format');

        $gateway->ask('hello', new RuntimeContext(loopIndex: 1));
    }

    public function testFormatRecoveryNeverAttemptsAThirdProvider(): void
    {
        $http = $this->createMock(OpenAiCompatibleHttpClientInterface::class);
        $http->expects(self::exactly(2))
            ->method('chatCompletion')
            ->willReturnOnConsecutiveCalls(
                [
                    'statusCode' => 200,
                    'data' => [],
                    'latencyMs' => 12.0,
                    'promptTokens' => 10,
                    'completionTokens' => 5,
                ],
                [
                    'statusCode' => 401,
                    'data' => ['error' => ['code' => 'invalid_api_key']],
                    'latencyMs' => 8.0,
                    'promptTokens' => null,
                    'completionTokens' => null,
                ],
            );
        $http->expects(self::once())
            ->method('extractContent')
            ->willReturn('bad reply without state');
        $http->expects(self::once())
            ->method('summarizeErrorPayload')
            ->willReturn(['code' => 'invalid_api_key']);

        $gateway = $this->makeGateway($http, $this->catalogWithTwoFallbacks());

        $this->expectException(\RuntimeException::class);
        $gateway->ask('hello', new RuntimeContext(loopIndex: 1));
    }

    public function testDoesNotCascadeOnRequestGlobalHttpStatus(): void
    {
        $http = $this->createMock(OpenAiCompatibleHttpClientInterface::class);
        $http->expects(self::once())
            ->method('chatCompletion')
            ->willReturn([
                'statusCode' => 422,
                'data' => ['error' => ['message' => 'invalid request', 'code' => 'invalid_request']],
                'latencyMs' => 8.0,
                'promptTokens' => null,
                'completionTokens' => null,
            ]);
        $http->method('summarizeErrorPayload')->willReturn([
            'code' => 'invalid_request',
        ]);

        $gateway = $this->makeGateway($http, $this->catalogWithFallback());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rejected the request');

        $gateway->ask('hello', new RuntimeContext(loopIndex: 1));
    }

    public function testFallsBackAfterProviderLocalAuthenticationFailure(): void
    {
        $http = $this->createMock(OpenAiCompatibleHttpClientInterface::class);
        $http->expects(self::exactly(2))
            ->method('chatCompletion')
            ->willReturnOnConsecutiveCalls(
                [
                    'statusCode' => 401,
                    'data' => ['error' => ['code' => 'invalid_api_key']],
                    'latencyMs' => 8.0,
                    'promptTokens' => null,
                    'completionTokens' => null,
                ],
                [
                    'statusCode' => 200,
                    'data' => [],
                    'latencyMs' => 10.0,
                    'promptTokens' => 10,
                    'completionTokens' => 5,
                ],
            );
        $http->method('summarizeErrorPayload')->willReturn(['code' => 'invalid_api_key']);
        $http->method('extractContent')->willReturn('Fallback.[STATE]KINDNESS=0;SUSPICION=0');

        $gateway = $this->makeGateway($http, $this->catalogWithFallback());

        self::assertSame(
            'Fallback.[STATE]KINDNESS=0;SUSPICION=0',
            $gateway->ask('hello', new RuntimeContext(loopIndex: 1))->content(),
        );
    }

    public function testFallsBackAfterRedirectResponse(): void
    {
        $http = $this->createMock(OpenAiCompatibleHttpClientInterface::class);
        $http->expects(self::exactly(2))
            ->method('chatCompletion')
            ->willReturnOnConsecutiveCalls(
                [
                    'statusCode' => 307,
                    'data' => [],
                    'latencyMs' => 8.0,
                    'promptTokens' => null,
                    'completionTokens' => null,
                ],
                [
                    'statusCode' => 200,
                    'data' => [],
                    'latencyMs' => 10.0,
                    'promptTokens' => 10,
                    'completionTokens' => 5,
                ],
            );
        $http->method('summarizeErrorPayload')->willReturn([]);
        $http->method('extractContent')->willReturn('Fallback.[STATE]KINDNESS=0;SUSPICION=0');

        $gateway = $this->makeGateway($http, $this->catalogWithFallback());

        self::assertSame(
            'Fallback.[STATE]KINDNESS=0;SUSPICION=0',
            $gateway->ask('hello', new RuntimeContext(loopIndex: 1))->content(),
        );
    }

    public function testDoesNotCascadeAfterStructurallyInvalidProviderResponse(): void
    {
        $http = $this->createMock(OpenAiCompatibleHttpClientInterface::class);
        $http->expects(self::once())
            ->method('chatCompletion')
            ->willReturn([
                'statusCode' => 200,
                'data' => [],
                'latencyMs' => 8.0,
                'promptTokens' => 10,
                'completionTokens' => 0,
            ]);
        $http->expects(self::once())
            ->method('extractContent')
            ->willThrowException(new \RuntimeException('AI provider returned invalid response.'));

        $gateway = $this->makeGateway($http, $this->catalogWithFallback());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid response');

        $gateway->ask('hello', new RuntimeContext(loopIndex: 1));
    }

    public function testReturnsValidatedReplyFromPrimary(): void
    {
        $http = $this->createMock(OpenAiCompatibleHttpClientInterface::class);
        $http->expects(self::once())
            ->method('chatCompletion')
            ->with(
                self::callback(static fn (array $provider): bool => $provider['label'] === 'primary'),
                self::callback(static fn (mixed $messages): bool => is_array($messages)),
                self::callback(static fn (mixed $maxTokens): bool => is_int($maxTokens)),
                self::callback(static fn (mixed $timeout): bool => is_float($timeout) && $timeout > 0.5),
            )
            ->willReturn([
                'statusCode' => 200,
                'data' => [],
                'latencyMs' => 20.0,
                'promptTokens' => 100,
                'completionTokens' => 40,
            ]);
        $http->method('extractContent')->willReturn('Hello.[STATE]KINDNESS=0;SUSPICION=0');

        $gateway = $this->makeGateway($http, $this->catalogPrimaryOnly());

        $message = $gateway->ask('hi', new RuntimeContext(loopIndex: 2));

        self::assertSame('assistant', $message->role());
        self::assertStringContainsString('[STATE]', $message->content());
    }

    private function makeGateway(
        OpenAiCompatibleHttpClientInterface $http,
        AiProviderCatalog $catalog,
    ): OpenAiCompatibleAiChatGateway {
        $projectDir = dirname(__DIR__, 4);

        return new OpenAiCompatibleAiChatGateway(
            providerCatalog: $catalog,
            routingPolicy: new ProviderRoutingPolicy(),
            promptFactory: new PromptFactory($projectDir . '/config/prompts', ''),
            httpClient: $http,
            replyFormatValidator: new AssistantReplyFormatValidator(),
            costEstimator: new CostEstimator(),
            logger: new NullLogger(),
            requestStack: new RequestStack(),
        );
    }

    private function catalogPrimaryOnly(): AiProviderCatalog
    {
        return new AiProviderCatalog(
            appEnv: 'test',
            primaryUrl: 'https://example.test/v1/chat/completions',
            primaryApiKey: 'key',
            primaryModel: 'gpt-test',
            primaryTlsVerify: 'true',
            fallbackEnabled: 'false',
            fallbackUrl: '',
            fallbackApiKey: '',
            fallbackModel: '',
            fallbackTlsVerify: 'true',
            fallback2Enabled: 'false',
            fallback2Url: '',
            fallback2ApiKey: '',
            fallback2Model: '',
            fallback2TlsVerify: 'true',
        );
    }

    private function catalogWithFallback(): AiProviderCatalog
    {
        return new AiProviderCatalog(
            appEnv: 'test',
            primaryUrl: 'https://example.test/v1/chat/completions',
            primaryApiKey: 'key',
            primaryModel: 'gpt-test',
            primaryTlsVerify: 'true',
            fallbackEnabled: 'true',
            fallbackUrl: 'https://example.test/fallback/v1/chat/completions',
            fallbackApiKey: 'fallback-key',
            fallbackModel: 'gpt-fallback',
            fallbackTlsVerify: 'true',
            fallback2Enabled: 'false',
            fallback2Url: '',
            fallback2ApiKey: '',
            fallback2Model: '',
            fallback2TlsVerify: 'true',
        );
    }

    private function catalogWithTwoFallbacks(): AiProviderCatalog
    {
        return new AiProviderCatalog(
            appEnv: 'test',
            primaryUrl: 'https://example.test/v1/chat/completions',
            primaryApiKey: 'key',
            primaryModel: 'gpt-best',
            primaryTlsVerify: 'true',
            fallbackEnabled: 'true',
            fallbackUrl: 'https://example.test/fallback/v1/chat/completions',
            fallbackApiKey: 'fallback-key',
            fallbackModel: 'gpt-balanced',
            fallbackTlsVerify: 'true',
            fallback2Enabled: 'true',
            fallback2Url: 'https://example.test/cheap/v1/chat/completions',
            fallback2ApiKey: 'cheap-key',
            fallback2Model: 'llama-cheap',
            fallback2TlsVerify: 'true',
        );
    }
}
