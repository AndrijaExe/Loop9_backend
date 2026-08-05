<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\Auth;

use App\Adapter\Auth\SteamTicketVerifier;
use App\Adapter\Auth\SteamVerificationUnavailableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SteamTicketVerifierTest extends TestCase
{
    public function testReturnsSteamIdForValidTicket(): void
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringStartsWith(
                'https://api.steampowered.com/ISteamUserAuth/AuthenticateUserTicket/v1/',
                $url,
            );
            self::assertStringNotContainsString('key=', $url);
            self::assertStringContainsString('appid=480', $url);
            self::assertStringContainsString('ticket=deadbeef', $url);
            self::assertStringContainsString('identity=Loop9', $url);
            self::assertSame(
                ['x-webapi-key: key'],
                $options['normalized_headers']['x-webapi-key'] ?? null,
            );

            return new MockResponse(json_encode([
                'response' => [
                    'params' => [
                        'result' => 'OK',
                        'steamid' => '76561198000000001',
                        'ownersteamid' => '76561198000000001',
                        'vacbanned' => false,
                        'publisherbanned' => false,
                    ],
                ],
            ], JSON_THROW_ON_ERROR));
        });

        $verifier = new SteamTicketVerifier($client, 'key', '480');

        $result = $verifier->verify('deadbeef');

        self::assertTrue($result->accepted);
        self::assertSame('76561198000000001', $result->steamId);
    }

    public function testThrowsWhenSteamEndpointRejectsKey(): void
    {
        $client = new MockHttpClient(new MockResponse('Access denied.', ['http_code' => 403]));
        $verifier = new SteamTicketVerifier($client, 'wrong-key', '480');

        try {
            $verifier->verify('deadbeef');
            self::fail('Expected Steam verification to be unavailable.');
        } catch (SteamVerificationUnavailableException $exception) {
            self::assertSame(SteamVerificationUnavailableException::REASON_UPSTREAM_STATUS, $exception->reason);
            self::assertSame(403, $exception->upstreamStatusCode);
        }
    }

    public function testReturnsRejectedResultForRecognizedTicketError(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'response' => [
                'error' => ['errorcode' => 3, 'errordesc' => 'Invalid parameter'],
            ],
        ], JSON_THROW_ON_ERROR)));

        $verifier = new SteamTicketVerifier($client, 'key', '480');

        $result = $verifier->verify('deadbeef');

        self::assertFalse($result->accepted);
        self::assertNull($result->steamId);
    }

    public function testRejectsPublisherBannedAccount(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'response' => [
                'params' => [
                    'result' => 'OK',
                    'steamid' => '76561198000000001',
                    'publisherbanned' => true,
                ],
            ],
        ], JSON_THROW_ON_ERROR)));

        $verifier = new SteamTicketVerifier($client, 'key', '480');

        $result = $verifier->verify('deadbeef');
        self::assertFalse($result->accepted);
        self::assertNull($result->steamId);
    }

    public function testThrowsOnTransportError(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));

        $verifier = new SteamTicketVerifier($client, 'key', '480');

        try {
            $verifier->verify('deadbeef');
            self::fail('Expected Steam verification to be unavailable.');
        } catch (SteamVerificationUnavailableException $exception) {
            self::assertSame(SteamVerificationUnavailableException::REASON_TRANSPORT, $exception->reason);
            self::assertNull($exception->upstreamStatusCode);
            self::assertNotNull($exception->getPrevious());
        }
    }

    public function testThrowsWhenNotConfigured(): void
    {
        $client = new MockHttpClient();
        $verifier = new SteamTicketVerifier($client, '', '');

        self::assertFalse($verifier->isConfigured());

        try {
            $verifier->verify('deadbeef');
            self::fail('Expected Steam verification to be unavailable.');
        } catch (SteamVerificationUnavailableException $exception) {
            self::assertSame(SteamVerificationUnavailableException::REASON_NOT_CONFIGURED, $exception->reason);
            self::assertNull($exception->upstreamStatusCode);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('malformedSuccessPayloads')]
    public function testThrowsForMalformedOrUnrecognizedHttp200Payload(array $payload): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR)));
        $verifier = new SteamTicketVerifier($client, 'key', '480');

        try {
            $verifier->verify('deadbeef');
            self::fail('Expected Steam verification to be unavailable.');
        } catch (SteamVerificationUnavailableException $exception) {
            self::assertSame(SteamVerificationUnavailableException::REASON_INVALID_RESPONSE, $exception->reason);
            self::assertSame(200, $exception->upstreamStatusCode);
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function malformedSuccessPayloads(): iterable
    {
        yield 'empty payload' => [[]];
        yield 'unrecognized error' => [[
            'response' => ['error' => ['message' => 'unknown']],
        ]];
        yield 'missing publisher ban status' => [[
            'response' => ['params' => [
                'result' => 'OK',
                'steamid' => '76561198000000001',
            ]],
        ]];
        yield 'non-boolean publisher ban status' => [[
            'response' => ['params' => [
                'result' => 'OK',
                'steamid' => '76561198000000001',
                'publisherbanned' => 0,
            ]],
        ]];
    }
}
