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
    public function __construct(
        private readonly AiProviderCatalog $providerCatalog,
        private readonly ProviderRoutingPolicy $routingPolicy,
        private readonly PromptFactory $promptFactory,
        private readonly OpenAiCompatibleHttpClient $httpClient,
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

        $lastException = null;

        foreach ($providers as $provider) {
            try {
                $response = $this->httpClient->chatCompletion($provider, $messages, $maxTokens);

                if ($response['statusCode'] >= 400) {
                    $this->logger->warning('AI provider returned error status.', [
                        'provider' => $provider['label'],
                        'statusCode' => $response['statusCode'],
                        'response' => $response['data'],
                    ]);
                    continue;
                }

                $rawContent = $this->httpClient->extractContent($response['data']);
                $validatedContent = $this->replyFormatValidator->normalizeAndValidate($rawContent);

                if ($validatedContent === null) {
                    $this->logger->warning('AI response format invalid; trying next provider.', [
                        'provider' => $provider['label'],
                        'model' => $provider['model'],
                        'contentPreview' => mb_substr($rawContent, 0, 180),
                    ]);
                    continue;
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
                    'exception' => $exception,
                ]);
            }
        }

        if ($lastException !== null) {
            throw new \RuntimeException('Unable to reach AI provider.', previous: $lastException);
        }

        throw new \RuntimeException('AI provider returned an error.');
    }
}
