<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\DTO\ChatResponseDTO;
use App\Model\Chat\AdviceDirective;
use App\Model\Chat\AiChatGateway;
use App\Model\Chat\ContentSafetyGateway;
use App\Model\Chat\Message;
use App\Model\Chat\RuntimeContext;
use App\Model\Chat\SafeChatFallbackFactory;

final class ChatService
{
    public function __construct(
        private readonly AiChatGateway $aiChatGateway,
        private readonly ContentSafetyGateway $contentSafety,
        private readonly SafeChatFallbackFactory $safeFallbacks,
    ) {
    }

    public function __invoke(string $playerMessage, RuntimeContext $context): ChatResponseDTO
    {
        $trimmed = trim($playerMessage);

        if ($trimmed === '') {
            throw new \InvalidArgumentException('Message cannot be empty.');
        }

        $inputDecision = $this->contentSafety->evaluate($trimmed, ContentSafetyGateway::STAGE_INPUT);
        if (!$inputDecision->isSafe()) {
            return $this->responseFromMessage(
                $this->safeFallbacks->create($context, $inputDecision->reason())
            );
        }

        $assistantMessage = $this->aiChatGateway->ask($trimmed, $context);

        $outputDecision = $this->contentSafety->evaluate(
            $assistantMessage->content(),
            ContentSafetyGateway::STAGE_OUTPUT
        );
        if (!$outputDecision->isSafe()) {
            return $this->responseFromMessage(
                $this->safeFallbacks->create($context, $outputDecision->reason())
            );
        }

        return $this->responseFromMessage($assistantMessage);
    }

    private function responseFromMessage(Message $message): ChatResponseDTO
    {
        return new ChatResponseDTO(
            role: $message->role(),
            message: $message->content(),
            createdAt: new \DateTimeImmutable(),
            advice: $message->advice() ?? AdviceDirective::withhold(),
        );
    }
}
