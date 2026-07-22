<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\Auth;

use App\Adapter\Auth\SteamTicketVerifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SteamTicketVerifierTest extends TestCase
{
    public function testReturnsSteamIdForValidTicket(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'response' => [
                'params' => [
                    'result' => 'OK',
                    'steamid' => '76561198000000001',
                    'ownersteamid' => '76561198000000001',
                    'vacbanned' => false,
                    'publisherbanned' => false,
                ],
            ],
        ], JSON_THROW_ON_ERROR)));

        $verifier = new SteamTicketVerifier($client, new NullLogger(), 'key', '480');

        self::assertSame('76561198000000001', $verifier->verify('deadbeef'));
    }

    public function testReturnsNullForRejectedTicket(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'response' => [
                'error' => ['errorcode' => 3, 'errordesc' => 'Invalid parameter'],
            ],
        ], JSON_THROW_ON_ERROR)));

        $verifier = new SteamTicketVerifier($client, new NullLogger(), 'key', '480');

        self::assertNull($verifier->verify('deadbeef'));
    }

    public function testReturnsNullOnTransportError(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['error' => 'Connection refused']));

        $verifier = new SteamTicketVerifier($client, new NullLogger(), 'key', '480');

        self::assertNull($verifier->verify('deadbeef'));
    }

    public function testReturnsNullWhenNotConfigured(): void
    {
        $client = new MockHttpClient();
        $verifier = new SteamTicketVerifier($client, new NullLogger(), '', '');

        self::assertFalse($verifier->isConfigured());
        self::assertNull($verifier->verify('deadbeef'));
    }
}
