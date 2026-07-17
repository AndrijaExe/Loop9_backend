<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Http;

use App\Infrastructure\Auth\SessionTokenIssuer;
use App\Infrastructure\Http\GameTokenAuthenticator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class GameTokenAuthenticatorTest extends TestCase
{
    private SessionTokenIssuer $issuer;

    protected function setUp(): void
    {
        $this->issuer = new SessionTokenIssuer('unit-secret', 3600);
    }

    public function testValidSessionTokenYieldsVerifiedPlayerId(): void
    {
        $authenticator = new GameTokenAuthenticator('shared-token', true, 'test', $this->issuer);
        $issued = $this->issuer->issue('steam-76561198000000001');

        $request = Request::create('/api/chat', 'POST', server: [
            'HTTP_X_SESSION_TOKEN' => $issued['token'],
        ]);

        $result = $authenticator->authenticate($request);

        self::assertSame('session', $result->scope);
        self::assertSame('steam-76561198000000001', $result->playerId);
    }

    public function testInvalidSessionTokenIsRejectedEvenIfGameTokenAllowed(): void
    {
        $authenticator = new GameTokenAuthenticator('shared-token', true, 'test', $this->issuer);

        $request = Request::create('/api/chat', 'POST', server: [
            'HTTP_X_SESSION_TOKEN' => 'v1.bogus.bogus',
            'HTTP_X_GAME_TOKEN' => 'shared-token',
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $authenticator->authenticate($request);
    }

    public function testGameTokenStillWorksWhenAllowed(): void
    {
        $authenticator = new GameTokenAuthenticator('shared-token', true, 'test', $this->issuer);

        $request = Request::create('/api/chat', 'POST', server: [
            'HTTP_X_GAME_TOKEN' => 'shared-token',
        ]);

        $result = $authenticator->authenticate($request);

        self::assertSame('game-token', $result->scope);
        self::assertNull($result->playerId);
    }

    public function testGameTokenRejectedWhenDisabled(): void
    {
        $authenticator = new GameTokenAuthenticator('shared-token', false, 'test', $this->issuer);

        $request = Request::create('/api/chat', 'POST', server: [
            'HTTP_X_GAME_TOKEN' => 'shared-token',
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $authenticator->authenticate($request);
    }

    public function testWrongGameTokenRejected(): void
    {
        $authenticator = new GameTokenAuthenticator('shared-token', true, 'test', $this->issuer);

        $request = Request::create('/api/chat', 'POST', server: [
            'HTTP_X_GAME_TOKEN' => 'wrong',
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $authenticator->authenticate($request);
    }

    public function testGameTokenIsAlwaysRejectedInProduction(): void
    {
        $authenticator = new GameTokenAuthenticator('shared-token', true, 'prod', $this->issuer);

        $request = Request::create('/api/chat', 'POST', server: [
            'HTTP_X_GAME_TOKEN' => 'shared-token',
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $authenticator->authenticate($request);
    }
}
