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

    public function testCarriesOnlyExplicitlyAllowedObservationSnapshotIntoContext(): void
    {
        $mapped = (new ChatRequestMapper())->map(Request::create(
            '/api/chat',
            'POST',
            content: json_encode([
                'message' => 'hello',
                'observation_snapshot' => [
                    'current_zone' => 'archive',
                    'events' => [['type' => 'door_opened', 'zone' => 'archive']],
                ],
                'raw_chat_history' => ['must not enter runtime context'],
            ], JSON_THROW_ON_ERROR),
        ));

        self::assertSame(
            'archive',
            $mapped['context']->observationSnapshot()?->toPromptArray()['current_zone'],
        );
        self::assertFalse(method_exists($mapped['context'], 'rawChatHistory'));
    }

    public function testRejectsObservationSnapshotOverEncodedByteLimit(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Field "observation_snapshot" must encode to at most 2048 bytes.');

        (new ChatRequestMapper())->map(Request::create(
            '/api/chat',
            'POST',
            content: json_encode([
                'message' => 'hello',
                'observation_snapshot' => ['events' => str_repeat('x', 2100)],
            ], JSON_THROW_ON_ERROR),
        ));
    }

    public function testRejectsObservationSnapshotListInsteadOfObject(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage('Field "observation_snapshot" must be an object.');

        (new ChatRequestMapper())->map(Request::create(
            '/api/chat',
            'POST',
            content: json_encode([
                'message' => 'hello',
                'observation_snapshot' => [
                    ['events' => []],
                ],
            ], JSON_THROW_ON_ERROR),
        ));
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
