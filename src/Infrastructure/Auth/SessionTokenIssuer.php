<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Issues and validates stateless, HMAC-signed session tokens.
 *
 * Format: v1.<base64url(payload json)>.<base64url(hmac-sha256)>
 * Payload: {"pid": "<player id>", "iat": <unix>, "exp": <unix>}
 */
final class SessionTokenIssuer
{
    private const VERSION = 'v1';

    public function __construct(
        #[Autowire(env: 'SESSION_TOKEN_SECRET')]
        private readonly string $secret,
        #[Autowire(env: 'int:SESSION_TOKEN_TTL')]
        private readonly int $ttlSeconds,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->secret !== '' && $this->ttlSeconds > 0;
    }

    /**
     * @return array{token: string, expiresAt: int}
     */
    public function issue(string $playerId): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('SESSION_TOKEN_SECRET is not configured.');
        }

        $now = time();
        $expiresAt = $now + $this->ttlSeconds;

        $payload = $this->base64UrlEncode(json_encode([
            'pid' => $playerId,
            'iat' => $now,
            'exp' => $expiresAt,
        ], JSON_THROW_ON_ERROR));

        $signature = $this->sign($payload);

        return [
            'token' => self::VERSION . '.' . $payload . '.' . $signature,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Returns the player id when the token is valid and not expired, null otherwise.
     */
    public function validate(string $token): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            return null;
        }

        [, $payload, $signature] = $parts;

        if (!hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        $decoded = $this->base64UrlDecode($payload);
        if ($decoded === null) {
            return null;
        }

        try {
            $data = json_decode($decoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $playerId = $data['pid'] ?? null;
        $expiresAt = $data['exp'] ?? null;

        if (!is_string($playerId) || $playerId === '' || !is_int($expiresAt)) {
            return null;
        }

        if ($expiresAt < time()) {
            return null;
        }

        return $playerId;
    }

    private function sign(string $payload): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', self::VERSION . '.' . $payload, $this->secret, true));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
