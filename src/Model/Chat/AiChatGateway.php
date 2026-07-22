<?php

declare(strict_types=1);

namespace App\Model\Chat;

interface AiChatGateway
{
    public function ask(string $playerMessage, RuntimeContext $context): Message;
}
