<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class GameTokenAuthenticator
{
    public function __construct(
        #[Autowire(env: 'GAME_API_TOKEN')]
        private readonly string $gameApiToken,
    ) {
    }

    public function authenticate(Request $request): string
    {
        if ($this->gameApiToken === '') {
            throw new \RuntimeException('GAME_API_TOKEN is not configured.');
        }

        $token = (string) $request->headers->get('X-Game-Token', '');

        if (!hash_equals($this->gameApiToken, $token)) {
            throw new AccessDeniedHttpException('Invalid token.');
        }

        return $token;
    }
}
