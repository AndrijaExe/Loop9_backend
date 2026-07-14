<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\Chat\SendChatMessageHandler;
use App\Domain\Chat\Message;
use App\Domain\Chat\Port\AiChatGatewayInterface;
use App\Domain\Chat\RuntimeContext;
use PHPUnit\Framework\TestCase;

final class SendChatMessageHandlerTest extends TestCase
{
    public function testDelegatesToGatewayAndBuildsResponse(): void
    {
        $gateway = $this->createMock(AiChatGatewayInterface::class);
        $context = RuntimeContext::fromArray(['loop_index' => 3]);

        $gateway->expects(self::once())
            ->method('ask')
            ->with('hello', $context)
            ->willReturn(new Message('assistant', 'Go dark.[STATE]KINDNESS=0;SUSPICION=0'));

        $handler = new SendChatMessageHandler($gateway);
        $response = $handler('hello', $context);

        self::assertSame('assistant', $response->toArray()['role']);
        self::assertSame('Go dark.[STATE]KINDNESS=0;SUSPICION=0', $response->toArray()['message']);
        self::assertArrayHasKey('createdAt', $response->toArray());
    }

    public function testRejectsEmptyMessage(): void
    {
        $gateway = $this->createMock(AiChatGatewayInterface::class);
        $gateway->expects(self::never())->method('ask');

        $handler = new SendChatMessageHandler($gateway);

        $this->expectException(\InvalidArgumentException::class);
        $handler('   ', RuntimeContext::fromArray([]));
    }
}
