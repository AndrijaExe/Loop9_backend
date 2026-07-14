<?php

declare(strict_types=1);

namespace App\Domain\Chat\Port;

use App\Domain\Chat\Message;
use App\Domain\Chat\RuntimeContext;

interface AiChatGatewayInterface
{
    public function ask(string $playerMessage, RuntimeContext $context): Message;
}
