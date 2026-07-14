<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SteamAuthEndpointTest extends WebTestCase
{
    public function testReturnsServiceUnavailableWhenSteamNotConfigured(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/auth/steam',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ticket' => 'deadbeef'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(503);
        $payload = json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertIsArray($payload);
        self::assertSame('STEAM_AUTH_DISABLED', $payload['error']['code'] ?? null);
    }

    public function testOptionsReturnsNoContent(): void
    {
        $client = static::createClient();

        $client->request('OPTIONS', '/api/auth/steam');

        self::assertResponseStatusCodeSame(204);
    }

    public function testChatRejectsInvalidSessionToken(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/chat',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SESSION_TOKEN' => 'v1.bogus.bogus',
            ],
            content: json_encode(['message' => 'hi', 'player_id' => 'player-session-test'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
    }
}
