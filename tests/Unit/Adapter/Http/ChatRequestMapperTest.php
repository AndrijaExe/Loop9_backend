<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\Http;

use App\Adapter\Http\ChatRequestMapper;
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

    public function testRejectsWhitespaceOnlyMessageBeforeQuotaConsumption(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Message cannot be empty.');

        (new ChatRequestMapper())->map(Request::create(
            '/api/chat',
            'POST',
            content: '{"message":"   "}',
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

    /**
     * The context is built from an explicit list of keys, so a field the client
     * sends is dropped unless it is named here.
     */
    public function testCarriesAnomalyDetailThroughToTheContext(): void
    {
        $mapped = (new ChatRequestMapper())->map(Request::create(
            '/api/chat',
            'POST',
            content: json_encode([
                'message' => 'gde da gledam',
                'anomaly_detail' => ['zone' => 'the north corridor', 'object' => 'a ceiling light panel'],
            ], JSON_THROW_ON_ERROR),
        ));

        self::assertSame('the north corridor', $mapped['context']->anomalyDetail()?->zone());
        self::assertSame('a ceiling light panel', $mapped['context']->anomalyDetail()?->object());
    }

    public function testRejectsDeeplyNestedJson(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid JSON body.');

        $nested = ['message' => 'hi'];
        $cursor = &$nested;
        for ($i = 0; $i < ChatRequestMapper::MAX_JSON_DEPTH + 2; ++$i) {
            $cursor['child'] = [];
            $cursor = &$cursor['child'];
        }

        (new ChatRequestMapper())->map(Request::create(
            '/api/chat',
            'POST',
            content: json_encode($nested, JSON_THROW_ON_ERROR),
        ));
    }

    public function testRejectsMalformedJson(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Invalid JSON body.');

        (new ChatRequestMapper())->map(Request::create(
            '/api/chat',
            'POST',
            content: '{not-json',
        ));
    }
}
