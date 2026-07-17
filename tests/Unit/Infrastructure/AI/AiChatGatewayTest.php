<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\AI;

use App\Domain\Chat\Policy\ProviderRoutingPolicy;
use App\Domain\Chat\RuntimeContext;
use App\Domain\Chat\Validation\AssistantReplyFormatValidator;
use App\Infrastructure\AI\AiChatGateway;
use App\Infrastructure\AI\AiProviderCatalog;
use App\Infrastructure\AI\CostEstimator;
use App\Infrastructure\AI\OpenAiCompatibleHttpClientInterface;
use App\Infrastructure\AI\PromptFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;

final class AiChatGatewayTest extends TestCase
{
    public function testDoesNotCascadeAfterInvalidFormat(): void
    {
        $http = $this->createMock(OpenAiCompatibleHttpClientInterface::class);
        $http->expects(self::once())
            ->method('chatCompletion')
            ->willReturn([
                'statusCode' => 200,
                'data' => ['choices' => [['message' => ['content' => 'bad']]]],
                'latencyMs' => 12.0,
                'promptTokens' => 10,
                'completionTokens' => 5,
            ]);
        $http->method('extractContent')->willReturn('bad reply without state');
        $http->expects(self::never())->method('summarizeErrorPayload');

        $gateway = $this->makeGateway($http, $this->catalogWithFallback());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invalid reply format');

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
    ): AiChatGateway {
        $projectDir = dirname(__DIR__, 4);

        return new AiChatGateway(
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
}
