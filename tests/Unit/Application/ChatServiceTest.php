<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application;

use App\Application\ChatService;
use App\Model\Chat\ContentSafetyDecision;
use App\Model\Chat\Message;
use App\Model\Chat\AiChatGateway;
use App\Model\Chat\ContentSafetyGateway;
use App\Model\Chat\RuntimeContext;
use App\Model\Chat\SafeChatFallbackFactory;
use PHPUnit\Framework\TestCase;

final class ChatServiceTest extends TestCase
{
    public function testDelegatesToGatewayAndBuildsResponse(): void
    {
        $gateway = $this->createMock(AiChatGateway::class);
        $safety = $this->createMock(ContentSafetyGateway::class);
        $context = RuntimeContext::fromArray(['loop_index' => 3]);

        $gateway->expects(self::once())
            ->method('ask')
            ->with('hello', $context)
            ->willReturn(new Message('assistant', 'Go dark.[STATE]KINDNESS=0;SUSPICION=0'));
        $safety->expects(self::exactly(2))
            ->method('evaluate')
            ->willReturn(ContentSafetyDecision::safe());

        $handler = $this->makeHandler($gateway, $safety);
        $response = $handler('hello', $context);

        self::assertSame('assistant', $response->toArray()['role']);
        self::assertSame('Go dark.[STATE]KINDNESS=0;SUSPICION=0', $response->toArray()['message']);
        self::assertArrayHasKey('createdAt', $response->toArray());
    }

    public function testRejectsEmptyMessage(): void
    {
        $gateway = $this->createMock(AiChatGateway::class);
        $gateway->expects(self::never())->method('ask');
        $safety = $this->createMock(ContentSafetyGateway::class);
        $safety->expects(self::never())->method('evaluate');

        $handler = $this->makeHandler($gateway, $safety);

        $this->expectException(\InvalidArgumentException::class);
        $handler('   ', RuntimeContext::fromArray([]));
    }

    public function testBlocksUnsafeInputBeforeCallingAi(): void
    {
        $gateway = $this->createMock(AiChatGateway::class);
        $gateway->expects(self::never())->method('ask');
        $safety = $this->createMock(ContentSafetyGateway::class);
        $safety->expects(self::once())
            ->method('evaluate')
            ->with('unsafe request', ContentSafetyGateway::STAGE_INPUT)
            ->willReturn(ContentSafetyDecision::blocked('sexual'));

        $response = ($this->makeHandler($gateway, $safety))(
            'unsafe request',
            RuntimeContext::fromArray(['language' => 'en'])
        );

        self::assertSame(
            'Leave that alone—watch the floor and tell me what changed.[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=0',
            $response->toArray()['message']
        );
    }

    public function testReplacesUnsafeOutputWithSerbianFallback(): void
    {
        $gateway = $this->createStub(AiChatGateway::class);
        $gateway->method('ask')->willReturn(
            new Message('assistant', 'unsafe output[STATE]KINDNESS=0;SUSPICION=0')
        );
        $safety = $this->createMock(ContentSafetyGateway::class);
        $safety->expects(self::exactly(2))
            ->method('evaluate')
            ->willReturnOnConsecutiveCalls(
                ContentSafetyDecision::safe(),
                ContentSafetyDecision::blocked('hate')
            );

        $response = ($this->makeHandler($gateway, $safety))(
            'zdravo',
            RuntimeContext::fromArray(['language' => 'sr'])
        );

        self::assertSame(
            'Pusti to sada—gledaj sprat i reci mi šta se promenilo.[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=0',
            $response->toArray()['message']
        );
    }

    public function testFailsClosedWhenModerationIsUnavailable(): void
    {
        $gateway = $this->createMock(AiChatGateway::class);
        $gateway->expects(self::never())->method('ask');
        $safety = $this->createStub(ContentSafetyGateway::class);
        $safety->method('evaluate')->willReturn(
            ContentSafetyDecision::blocked('moderation_unavailable')
        );

        $response = ($this->makeHandler($gateway, $safety))(
            'hello',
            RuntimeContext::fromArray(['language' => 'en'])
        );

        self::assertStringStartsWith('The line is breaking up...', $response->toArray()['message']);
    }

    private function makeHandler(
        AiChatGateway $gateway,
        ContentSafetyGateway $safety,
    ): ChatService {
        return new ChatService(
            aiChatGateway: $gateway,
            contentSafety: $safety,
            safeFallbacks: new SafeChatFallbackFactory(),
        );
    }
}
