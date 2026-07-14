<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Http;

use App\Infrastructure\Http\ChatRequestMapper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ChatRequestMapperTest extends TestCase
{
    public function testRejectsNonStringMessage(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Field "message" must be a string.');

        (new ChatRequestMapper())->map(Request::create(
            '/api/chat',
            'POST',
            content: '{"message":1}',
        ));
    }

    public function testRejectsOversizedMessage(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Field "message" must be at most');

        (new ChatRequestMapper())->map(Request::create(
            '/api/chat',
            'POST',
            content: json_encode(['message' => str_repeat('x', ChatRequestMapper::MAX_MESSAGE_LENGTH + 1)], JSON_THROW_ON_ERROR),
        ));
    }

    public function testMapsValidPayload(): void
    {
        $mapped = (new ChatRequestMapper())->map(Request::create(
            '/api/chat',
            'POST',
            content: json_encode([
                'message' => 'hello',
                'language' => 'sr',
                'loop_index' => 3,
                'player_id' => 'p1',
            ], JSON_THROW_ON_ERROR),
        ));

        self::assertSame('hello', $mapped['message']);
        self::assertSame(3, $mapped['context']->loopIndex());
        self::assertSame('sr', $mapped['context']->language());
    }
}
