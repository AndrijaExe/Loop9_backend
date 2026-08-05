<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use App\Model\Chat\Message;
use App\Model\Chat\ContentSafetyDecision;
use App\Model\Chat\AiChatGateway;
use App\Model\Chat\ContentSafetyGateway;
use App\Model\Chat\RuntimeContext;
use App\Adapter\Auth\SessionTokenIssuer;
use App\Adapter\Auth\SteamTicketVerifier;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

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

    public function testAcceptsMaximumLengthSteamWebApiTicket(): void
    {
        $client = static::createClient();
        static::getContainer()->set(
            SteamTicketVerifier::class,
            $this->configuredSteamVerifier(),
        );

        $client->request(
            'POST',
            '/api/auth/steam',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ticket' => str_repeat('a', 5120)], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(200);
    }

    public function testMapsRejectedTicketToForbidden(): void
    {
        $client = static::createClient();
        static::getContainer()->set(
            SteamTicketVerifier::class,
            $this->steamVerifierWithResponse([
                'response' => [
                    'error' => ['errorcode' => 3, 'errordesc' => 'Invalid parameter'],
                ],
            ]),
        );

        $client->request(
            'POST',
            '/api/auth/steam',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ticket' => 'deadbeef'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertSame('STEAM_TICKET_INVALID', $payload['error']['code'] ?? null);
    }

    public function testMapsSteamUpstreamFailureToServiceUnavailable(): void
    {
        $client = static::createClient();
        static::getContainer()->set(
            SteamTicketVerifier::class,
            new SteamTicketVerifier(
                new MockHttpClient(new MockResponse('Unavailable', ['http_code' => 503])),
                'publisher-key',
                '4982260',
            ),
        );

        $client->request(
            'POST',
            '/api/auth/steam',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ticket' => 'deadbeef'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(503);
        $payload = json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertSame('STEAM_UPSTREAM_UNAVAILABLE', $payload['error']['code'] ?? null);
        self::assertSame(
            'Steam ticket verification is temporarily unavailable.',
            $payload['error']['message'] ?? null,
        );
    }

    public function testMapsMalformedSteamSuccessPayloadToServiceUnavailable(): void
    {
        $client = static::createClient();
        static::getContainer()->set(
            SteamTicketVerifier::class,
            $this->steamVerifierWithResponse([
                'response' => [
                    'params' => [
                        'result' => 'OK',
                        'steamid' => '76561198000000001',
                    ],
                ],
            ]),
        );

        $client->request(
            'POST',
            '/api/auth/steam',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ticket' => 'deadbeef'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(503);
        $payload = json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertSame('STEAM_UPSTREAM_UNAVAILABLE', $payload['error']['code'] ?? null);
    }

    public function testRejectsPublisherBannedSteamAccount(): void
    {
        $client = static::createClient();
        static::getContainer()->set(
            SteamTicketVerifier::class,
            $this->steamVerifierWithResponse([
                'response' => [
                    'params' => [
                        'result' => 'OK',
                        'steamid' => '76561198000000001',
                        'publisherbanned' => true,
                    ],
                ],
            ]),
        );

        $client->request(
            'POST',
            '/api/auth/steam',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ticket' => 'deadbeef'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode($client->getResponse()->getContent() ?: '', true);
        self::assertSame('STEAM_TICKET_INVALID', $payload['error']['code'] ?? null);
    }

    public function testRejectsSteamWebApiTicketAboveMaximumLength(): void
    {
        $client = static::createClient();
        static::getContainer()->set(
            SteamTicketVerifier::class,
            $this->configuredSteamVerifier(),
        );

        $client->request(
            'POST',
            '/api/auth/steam',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ticket' => str_repeat('a', 5122)], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
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

    private function configuredSteamVerifier(): SteamTicketVerifier
    {
        return $this->steamVerifierWithResponse([
            'response' => [
                'params' => [
                    'result' => 'OK',
                    'steamid' => '76561198000000001',
                    'publisherbanned' => false,
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function steamVerifierWithResponse(array $payload): SteamTicketVerifier
    {
        return new SteamTicketVerifier(
            new MockHttpClient(new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR))),
            'publisher-key',
            '4982260',
        );
    }
}
