<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Model\Chat\Message;
use App\Model\Chat\ContentSafetyDecision;
use App\Model\Chat\AiChatGateway;
use App\Model\Chat\ContentSafetyGateway;
use App\Model\Chat\RuntimeContext;
use App\Adapter\Auth\SessionTokenIssuer;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SteamAuthTest extends WebTestCase
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

        static::getContainer()->set(AiChatGateway::class, new class implements AiChatGateway {
            public function ask(string $playerMessage, RuntimeContext $context): Message
            {
                return new Message('assistant', 'Stub reply.');
            }
        });
        static::getContainer()->set(ContentSafetyGateway::class, new class implements ContentSafetyGateway {
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
                'HTTP_X_REQUEST_ID' => '77b35058-34ae-43bc-bdb9-565656101e91',
            ],
            content: json_encode(['message' => 'hi', 'player_id' => 'x'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('X-Request-Id', '77b35058-34ae-43bc-bdb9-565656101e91');
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
