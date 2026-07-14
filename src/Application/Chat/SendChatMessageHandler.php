<?php

declare(strict_types=1);

namespace App\Application\Chat;

use App\Domain\Chat\Port\AiChatGatewayInterface;
use App\Domain\Chat\RuntimeContext;

final class SendChatMessageHandler
{
    public function __construct(
        private readonly AiChatGatewayInterface $aiChatGateway,
    ) {
    }

    public function __invoke(string $playerMessage, RuntimeContext $context): ChatResponse
    {
        $trimmed = trim($playerMessage);

        if ($trimmed === '') {
            throw new \InvalidArgumentException('Message cannot be empty.');
        }

        $assistantMessage = $this->aiChatGateway->ask($trimmed, $context);

        return new ChatResponse(
            role: $assistantMessage->role(),
            message: $assistantMessage->content(),
            createdAt: new \DateTimeImmutable(),
        );
    }
}
