<?php

declare(strict_types=1);

namespace App\Application\Chat;

use App\Domain\Chat\Port\AiChatGatewayInterface;
use App\Domain\Chat\Message;
use App\Domain\Chat\Port\ContentSafetyGatewayInterface;
use App\Domain\Chat\RuntimeContext;
use App\Domain\Chat\SafeChatFallbackFactory;

final class SendChatMessageHandler
{
    public function __construct(
        private readonly AiChatGatewayInterface $aiChatGateway,
        private readonly ContentSafetyGatewayInterface $contentSafety,
        private readonly SafeChatFallbackFactory $safeFallbacks,
    ) {
    }

    public function __invoke(string $playerMessage, RuntimeContext $context): ChatResponse
    {
        $trimmed = trim($playerMessage);

        if ($trimmed === '') {
            throw new \InvalidArgumentException('Message cannot be empty.');
        }

        $inputDecision = $this->contentSafety->evaluate($trimmed, ContentSafetyGatewayInterface::STAGE_INPUT);
        if (!$inputDecision->isSafe()) {
            return $this->responseFromMessage(
                $this->safeFallbacks->create($context, $inputDecision->reason())
            );
        }

        $assistantMessage = $this->aiChatGateway->ask($trimmed, $context);

        $outputDecision = $this->contentSafety->evaluate(
            $assistantMessage->content(),
            ContentSafetyGatewayInterface::STAGE_OUTPUT
        );
        if (!$outputDecision->isSafe()) {
            return $this->responseFromMessage(
                $this->safeFallbacks->create($context, $outputDecision->reason())
            );
        }

        return $this->responseFromMessage($assistantMessage);
    }

    private function responseFromMessage(Message $message): ChatResponse
    {
        return new ChatResponse(
            role: $message->role(),
            message: $message->content(),
            createdAt: new \DateTimeImmutable(),
        );
    }
}
