<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Infrastructure\Auth\SessionTokenIssuer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Authenticates chat requests either via a short-lived session token
 * (issued after Steam ticket verification) or via the legacy shared
 * game token (dev / non-Steam builds; can be disabled in prod).
 */
final class GameTokenAuthenticator
{
    public function __construct(
        #[Autowire(env: 'GAME_API_TOKEN')]
        private readonly string $gameApiToken,
        #[Autowire(env: 'bool:AUTH_ALLOW_GAME_TOKEN')]
        private readonly bool $allowGameToken,
        #[Autowire(env: 'APP_ENV')]
        private readonly string $appEnv,
        private readonly SessionTokenIssuer $sessionTokens,
    ) {
    }

    public function authenticate(Request $request): AuthResult
    {
        $sessionToken = (string) $request->headers->get('X-Session-Token', '');

        if ($sessionToken !== '') {
            $playerId = $this->sessionTokens->validate($sessionToken);

            if ($playerId === null) {
                throw new AccessDeniedHttpException('Invalid or expired session token.');
            }

            return new AuthResult(scope: 'session', playerId: $playerId);
        }

        if (!$this->allowGameToken || $this->appEnv === 'prod') {
            throw new AccessDeniedHttpException('Session token required.');
        }

        if ($this->gameApiToken === '') {
            throw new \RuntimeException('GAME_API_TOKEN is not configured.');
        }

        $token = (string) $request->headers->get('X-Game-Token', '');

        if (!hash_equals($this->gameApiToken, $token)) {
            throw new AccessDeniedHttpException('Invalid token.');
        }

        return new AuthResult(scope: 'game-token', playerId: null);
    }
}
