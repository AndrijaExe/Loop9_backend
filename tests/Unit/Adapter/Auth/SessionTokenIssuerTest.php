<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\Auth;

use App\Adapter\Auth\SessionTokenIssuer;
use PHPUnit\Framework\TestCase;

final class SessionTokenIssuerTest extends TestCase
{
    public function testIssuedTokenValidatesBackToPlayerId(): void
    {
        $issuer = new SessionTokenIssuer('secret-a', 3600);

        $issued = $issuer->issue('steam-76561198000000001');

        self::assertSame('steam-76561198000000001', $issuer->validate($issued['token']));
        self::assertGreaterThan(time(), $issued['expiresAt']);
    }

    public function testRejectsTokenSignedWithDifferentSecret(): void
    {
        $issuerA = new SessionTokenIssuer('secret-a', 3600);
        $issuerB = new SessionTokenIssuer('secret-b', 3600);

        $issued = $issuerA->issue('steam-76561198000000001');

        self::assertNull($issuerB->validate($issued['token']));
    }

    public function testRejectsTamperedPayload(): void
    {
        $issuer = new SessionTokenIssuer('secret-a', 3600);
        $issued = $issuer->issue('steam-76561198000000001');

        [$version, $payload, $signature] = explode('.', $issued['token']);
        $forgedPayload = rtrim(strtr(base64_encode(json_encode([
            'pid' => 'steam-attacker',
            'iat' => time(),
            'exp' => time() + 3600,
        ])), '+/', '-_'), '=');

        self::assertNull($issuer->validate($version . '.' . $forgedPayload . '.' . $signature));
    }

    public function testRejectsExpiredToken(): void
    {
        $issuer = new SessionTokenIssuer('secret-a', 3600);

        $payload = rtrim(strtr(base64_encode(json_encode([
            'pid' => 'steam-76561198000000001',
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', 'v1.' . $payload, 'secret-a', true)
        ), '+/', '-_'), '=');

        self::assertNull($issuer->validate('v1.' . $payload . '.' . $signature));
    }

    public function testRejectsGarbage(): void
    {
        $issuer = new SessionTokenIssuer('secret-a', 3600);

        self::assertNull($issuer->validate(''));
        self::assertNull($issuer->validate('not-a-token'));
        self::assertNull($issuer->validate('v1.abc.def'));
    }

    public function testUnconfiguredIssuerValidatesNothing(): void
    {
        $configured = new SessionTokenIssuer('secret-a', 3600);
        $unconfigured = new SessionTokenIssuer('', 3600);

        $issued = $configured->issue('steam-76561198000000001');

        self::assertFalse($unconfigured->isConfigured());
        self::assertNull($unconfigured->validate($issued['token']));
    }
}
