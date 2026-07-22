<?php

declare(strict_types=1);

namespace App\Adapter\Http;

/**
 * Result of chat request authentication.
 *
 * When authenticated via session token, playerId is authoritative
 * (derived from the verified Steam identity) and client-supplied
 * player_id values are ignored.
 */
final readonly class AuthResult
{
    public function __construct(
        public string $scope,
        public ?string $playerId,
    ) {
    }
}
