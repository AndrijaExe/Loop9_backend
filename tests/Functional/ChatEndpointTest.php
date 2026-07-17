<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ChatEndpointTest extends WebTestCase
{
    public function testRejectsMissingToken(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/chat',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['message' => 'hi'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertIsArray($payload);
        self::assertSame('REQUEST_ERROR', $payload['error']['code'] ?? null);
    }

    public function testRejectsInvalidToken(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/chat',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GAME_TOKEN' => 'wrong-token',
            ],
            content: json_encode(['message' => 'hi', 'player_id' => 'player-wrong-token'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testRejectsInvalidJson(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/chat',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GAME_TOKEN' => $this->gameToken(),
            ],
            content: '{not-json',
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testRejectsMissingPlayerId(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/chat',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GAME_TOKEN' => $this->gameToken(),
            ],
            content: json_encode(['message' => 'hi'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertIsArray($payload);
        self::assertSame('REQUEST_ERROR', $payload['error']['code'] ?? null);
    }

    public function testRejectsEmptyMessage(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/chat',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GAME_TOKEN' => $this->gameToken(),
            ],
            content: json_encode(['message' => '   ', 'player_id' => 'player-test'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertIsArray($payload);
        self::assertSame('Message cannot be empty.', $payload['error']['message'] ?? null);
    }

    public function testRejectsOversizedMessage(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/chat',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GAME_TOKEN' => $this->gameToken(),
            ],
            content: json_encode([
                'message' => str_repeat('a', 4001),
                'player_id' => 'player-test',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
        $payload = json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertIsArray($payload);
        self::assertStringContainsString('message', (string) ($payload['error']['message'] ?? ''));
    }

    public function testOptionsReturnsNoContent(): void
    {
        $client = static::createClient();

        $client->request('OPTIONS', '/api/chat');

        self::assertResponseStatusCodeSame(204);
    }

    public function testRejectsOversizedBody(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/chat',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GAME_TOKEN' => $this->gameToken(),
                'CONTENT_LENGTH' => (string) (\App\Infrastructure\Http\RequestBodyLimitSubscriber::MAX_BODY_BYTES + 1),
            ],
            content: str_repeat('a', \App\Infrastructure\Http\RequestBodyLimitSubscriber::MAX_BODY_BYTES + 1),
        );

        self::assertResponseStatusCodeSame(413);
    }

    public function testReadyzIsReadyInTestEnvironment(): void
    {
        $client = static::createClient();

        $client->request('GET', '/readyz');

        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertIsArray($payload);
        self::assertSame('ready', $payload['status'] ?? null);
    }

    private function gameToken(): string
    {
        $token = $_ENV['GAME_API_TOKEN'] ?? $_SERVER['GAME_API_TOKEN'] ?? getenv('GAME_API_TOKEN');

        self::assertNotFalse($token);
        self::assertNotSame('', (string) $token);

        return (string) $token;
    }
}
