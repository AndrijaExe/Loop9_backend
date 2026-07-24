<?php

declare(strict_types=1);

namespace App\Adapter\AI;

use App\Model\Chat\Message;
use App\Model\Chat\ProviderRoutingPolicy;
use App\Model\Chat\AiChatGateway;
use App\Model\Chat\RuntimeContext;
use App\Model\Chat\AssistantReplyFormatValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class OpenAiCompatibleAiChatGateway implements AiChatGateway
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
        private readonly RequestStack $requestStack,
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
        $startedAt = hrtime(true);
        $lastException = null;
        $attempt = 0;
        $lastAllowedAttempt = count($providers);

        foreach ($providers as $provider) {
            ++$attempt;
            if ($attempt > $lastAllowedAttempt) {
                break;
            }

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
                        'requestId' => $this->requestId(),
                        'attempt' => $attempt,
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
                    $canTryOneFallback = $attempt < $lastAllowedAttempt;
                    if ($canTryOneFallback) {
                        // Once format recovery begins, the very next provider is
                        // the final paid attempt regardless of its outcome.
                        $lastAllowedAttempt = $attempt + 1;
                    }

                    $this->logger->warning('AI response format invalid.', [
                        'requestId' => $this->requestId(),
                        'attempt' => $attempt,
                        'provider' => $provider['label'],
                        'model' => $provider['model'],
                        'contentLength' => mb_strlen($rawContent),
                        'completionTokens' => $response['completionTokens'],
                        'maxTokens' => $maxTokens,
                        'willRetryOnce' => $canTryOneFallback,
                    ]);

                    if ($canTryOneFallback) {
                        continue;
                    }

                    throw new \RuntimeException(sprintf(
                        'AI provider "%s" returned an invalid reply format.',
                        $provider['label']
                    ));
                }

                $this->logger->info('AI provider selected for response.', [
                    'requestId' => $this->requestId(),
                    'attempt' => $attempt,
                    'fallbackCount' => $attempt - 1,
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
                    'totalAiMs' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                ]);

                return new Message('assistant', $validatedContent);
            } catch (\Throwable $exception) {
                $lastException = $exception;

                $this->logger->error('AI provider request failed.', [
                    'requestId' => $this->requestId(),
                    'attempt' => $attempt,
                    'provider' => $provider['label'],
                    'model' => $provider['model'],
                    'exceptionClass' => $exception::class,
                    'totalAiMs' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
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

    private function requestId(): string
    {
        $requestId = (string) $this->requestStack->getCurrentRequest()?->headers->get('X-Request-Id', '');

        return preg_match('/\A[A-Fa-f0-9-]{16,64}\z/', $requestId) === 1
            ? $requestId
            : 'unknown';
    }
}
