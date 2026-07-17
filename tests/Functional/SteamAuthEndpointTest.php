<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Domain\Chat\Message;
use App\Domain\Chat\ContentSafetyDecision;
use App\Domain\Chat\Port\AiChatGatewayInterface;
use App\Domain\Chat\Port\ContentSafetyGatewayInterface;
use App\Domain\Chat\RuntimeContext;
use App\Infrastructure\Auth\SessionTokenIssuer;
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

    public function testChatAcceptsValidSessionTokenAndIgnoresClientPlayerId(): void
    {
        $client = static::createClient();

        static::getContainer()->set(AiChatGatewayInterface::class, new class implements AiChatGatewayInterface {
            public function ask(string $playerMessage, RuntimeContext $context): Message
            {
                return new Message('assistant', 'Stub reply.');
            }
        });
        static::getContainer()->set(ContentSafetyGatewayInterface::class, new class implements ContentSafetyGatewayInterface {
            public function evaluate(string $text, string $stage): ContentSafetyDecision
            {
                return ContentSafetyDecision::safe();
            }
        });

        // Secret/TTL must match .env.test so the kernel validates this token.
        $issuer = new SessionTokenIssuer('test-session-secret', 3600);
        $issued = $issuer->issue('steam-76561198000000001');

        // player_id "x" would be rejected as too short if it were used; a 200
        // proves the identity comes from the session token instead.
        $client->request(
            'POST',
            '/api/chat',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SESSION_TOKEN' => $issued['token'],
            ],
            content: json_encode(['message' => 'hi', 'player_id' => 'x'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        $payload = json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertIsArray($payload);
        self::assertSame('Stub reply.', $payload['message'] ?? null);
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
