<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Domain\Chat\Message;
use App\Domain\Chat\Policy\ProviderRoutingPolicy;
use App\Domain\Chat\Port\AiChatGatewayInterface;
use App\Domain\Chat\RuntimeContext;
use App\Domain\Chat\Validation\AssistantReplyFormatValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AiChatGatewayInterface::class)]
final class AiChatGateway implements AiChatGatewayInterface
{
    /** Hard ceiling across all sequential provider attempts (seconds). */
    public const TOTAL_DEADLINE_SECONDS = 45.0;

    public function __construct(
        private readonly AiProviderCatalog $providerCatalog,
        private readonly ProviderRoutingPolicy $routingPolicy,
        private readonly PromptFactory $promptFactory,
        private readonly OpenAiCompatibleHttpClientInterface $httpClient,
        private readonly AssistantReplyFormatValidator $replyFormatValidator,
        private readonly CostEstimator $costEstimator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function ask(string $playerMessage, RuntimeContext $context): Message
    {
        $messages = $this->promptFactory->buildMessages($playerMessage, $context);
        $providers = $this->routingPolicy->orderByLoop(
            $this->providerCatalog->configuredProviders(),
            $context->loopIndex()
        );
        $maxTokens = $this->routingPolicy->maxTokensForLoop($context->loopIndex());

        $deadlineAt = microtime(true) + self::TOTAL_DEADLINE_SECONDS;
        $lastException = null;

        foreach ($providers as $provider) {
            $remaining = $deadlineAt - microtime(true);
            if ($remaining <= 0.5) {
                break;
            }

            try {
                $response = $this->httpClient->chatCompletion(
                    $provider,
                    $messages,
                    $maxTokens,
                    min(OpenAiCompatibleHttpClient::DEFAULT_TIMEOUT_SECONDS, $remaining),
                );

                if ($response['statusCode'] !== 200) {
                    $this->logger->warning('AI provider returned error status.', [
                        'provider' => $provider['label'],
                        'model' => $provider['model'],
                        'statusCode' => $response['statusCode'],
                        'error' => $this->httpClient->summarizeErrorPayload($response['data']),
                        'latencyMs' => $response['latencyMs'],
                    ]);

                    // Request-global 4xx errors should not burn another paid
                    // attempt. Provider-local auth/model/endpoint failures may
                    // still fall through to independently configured providers.
                    if ($this->isRequestGlobalFailure($response['statusCode'])) {
                        throw new \RuntimeException(sprintf(
                            'AI provider "%s" rejected the request (HTTP %d).',
                            $provider['label'],
                            $response['statusCode']
                        ));
                    }

                    continue;
                }

                $rawContent = $this->httpClient->extractContent($response['data']);
                $validatedContent = $this->replyFormatValidator->normalizeAndValidate($rawContent);

                if ($validatedContent === null) {
                    // Format failure is a prompt/model issue — do not multiply spend
                    // by silently cascading to every configured fallback.
                    $this->logger->warning('AI response format invalid; not cascading to fallbacks.', [
                        'provider' => $provider['label'],
                        'model' => $provider['model'],
                        'contentLength' => mb_strlen($rawContent),
                    ]);

                    throw new \RuntimeException(sprintf(
                        'AI provider "%s" returned an invalid reply format.',
                        $provider['label']
                    ));
                }

                $this->logger->info('AI provider selected for response.', [
                    'provider' => $provider['label'],
                    'model' => $provider['model'],
                    'statusCode' => $response['statusCode'],
                    'latencyMs' => $response['latencyMs'],
                    'promptTokens' => $response['promptTokens'],
                    'completionTokens' => $response['completionTokens'],
                    'estimatedCostUsd' => $this->costEstimator->estimateUsd(
                        $provider['model'],
                        $response['promptTokens'],
                        $response['completionTokens']
                    ),
                    'formatValidated' => true,
                ]);

                return new Message('assistant', $validatedContent);
            } catch (\Throwable $exception) {
                $lastException = $exception;

                $this->logger->error('AI provider request failed.', [
                    'provider' => $provider['label'],
                    'model' => $provider['model'],
                    'exceptionClass' => $exception::class,
                ]);

                if ($this->shouldStopCascading($exception)) {
                    throw $exception instanceof \RuntimeException
                        ? $exception
                        : new \RuntimeException('Unable to reach AI provider.', previous: $exception);
                }
            }
        }

        if ($lastException !== null) {
            throw new \RuntimeException('Unable to reach AI provider.', previous: $lastException);
        }

        throw new \RuntimeException('AI provider returned an error.');
    }

    private function isRequestGlobalFailure(int $statusCode): bool
    {
        return in_array($statusCode, [400, 405, 413, 415, 422], true);
    }

    private function shouldStopCascading(\Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'invalid reply format')
            || str_contains($message, 'invalid response')
            || str_contains($message, 'rejected the request');
    }
}
