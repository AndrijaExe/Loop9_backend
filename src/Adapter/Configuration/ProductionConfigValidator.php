<?php

declare(strict_types=1);

namespace App\Adapter\Configuration;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Fail-closed production readiness checks. Used by /readyz and optional
 * deploy-time validation so missing Render env vars surface as clear
 * startup/ops failures instead of silent security gaps.
 */
final class ProductionConfigValidator
{
    public function __construct(
        #[Autowire(env: 'APP_ENV')]
        private readonly string $appEnv,
        #[Autowire(env: 'TRUSTED_PROXIES')]
        private readonly string $trustedProxies,
        #[Autowire(env: 'REDIS_URL')]
        private readonly string $redisUrl,
        #[Autowire(env: 'SESSION_TOKEN_SECRET')]
        private readonly string $sessionTokenSecret,
        #[Autowire(env: 'int:SESSION_TOKEN_TTL')]
        private readonly int $sessionTokenTtl,
        #[Autowire(env: 'STEAM_WEB_API_KEY')]
        private readonly string $steamWebApiKey,
        #[Autowire(env: 'STEAM_APP_ID')]
        private readonly string $steamAppId,
        #[Autowire(env: 'bool:AUTH_ALLOW_GAME_TOKEN')]
        private readonly bool $allowGameToken,
        #[Autowire(env: 'GAME_API_TOKEN')]
        private readonly string $gameApiToken,
        #[Autowire(env: 'bool:AI_TLS_VERIFY')]
        private readonly bool $aiTlsVerify,
        #[Autowire(env: 'AI_API_KEY')]
        private readonly string $aiApiKey,
        #[Autowire(env: 'AI_CHAT_COMPLETIONS_URL')]
        private readonly string $aiUrl,
        #[Autowire(env: 'AI_MODEL')]
        private readonly string $aiModel,
        #[Autowire(env: 'bool:AI_FALLBACK_ENABLED')]
        private readonly bool $fallbackEnabled,
        #[Autowire(env: 'AI_FALLBACK_CHAT_COMPLETIONS_URL')]
        private readonly string $fallbackUrl,
        #[Autowire(env: 'AI_FALLBACK_API_KEY')]
        private readonly string $fallbackApiKey,
        #[Autowire(env: 'AI_FALLBACK_MODEL')]
        private readonly string $fallbackModel,
        #[Autowire(env: 'bool:AI_FALLBACK2_ENABLED')]
        private readonly bool $fallback2Enabled,
        #[Autowire(env: 'AI_FALLBACK2_CHAT_COMPLETIONS_URL')]
        private readonly string $fallback2Url,
        #[Autowire(env: 'AI_FALLBACK2_API_KEY')]
        private readonly string $fallback2ApiKey,
        #[Autowire(env: 'AI_FALLBACK2_MODEL')]
        private readonly string $fallback2Model,
        #[Autowire(env: 'AI_MODERATION_URL')]
        private readonly string $moderationUrl,
        #[Autowire(env: 'AI_MODERATION_API_KEY')]
        private readonly string $moderationApiKey,
        #[Autowire(env: 'AI_MODERATION_MODEL')]
        private readonly string $moderationModel,
        #[Autowire(env: 'int:AI_MODERATION_TIMEOUT_SECONDS')]
        private readonly int $moderationTimeoutSeconds,
    ) {
    }

    public function isProduction(): bool
    {
        return $this->appEnv === 'prod';
    }

    /**
     * @return list<string>
     */
    public function issues(): array
    {
        if (!$this->isProduction()) {
            return [];
        }

        $issues = [];

        if (trim($this->trustedProxies) === '') {
            $issues[] = 'TRUSTED_PROXIES must be set in production (e.g. 127.0.0.1,REMOTE_ADDR).';
        }

        if (trim($this->redisUrl) === '') {
            $issues[] = 'REDIS_URL must be set in production for durable rate limiting.';
        }

        if (trim($this->sessionTokenSecret) === '' || strlen($this->sessionTokenSecret) < 32) {
            $issues[] = 'SESSION_TOKEN_SECRET must be a strong secret (at least 32 characters).';
        }

        if ($this->sessionTokenTtl < 300) {
            $issues[] = 'SESSION_TOKEN_TTL must be at least 300 seconds in production.';
        }

        if (trim($this->steamWebApiKey) === '' || trim($this->steamAppId) === '') {
            $issues[] = 'STEAM_WEB_API_KEY and STEAM_APP_ID must be configured for Steam auth.';
        }

        if ($this->allowGameToken) {
            $issues[] = 'AUTH_ALLOW_GAME_TOKEN must be false in production (Steam session tokens only).';
        }

        if ($this->allowGameToken && ($this->gameApiToken === '' || $this->gameApiToken === 'change-this-token')) {
            $issues[] = 'GAME_API_TOKEN must not use the placeholder value when legacy auth is enabled.';
        }

        if (!$this->aiTlsVerify) {
            $issues[] = 'AI_TLS_VERIFY must remain true in production.';
        }

        if (trim($this->aiApiKey) === '') {
            $issues[] = 'AI_API_KEY must be configured in production.';
        }

        if (!$this->isHttpsUrl($this->aiUrl) || trim($this->aiModel) === '') {
            $issues[] = 'AI_CHAT_COMPLETIONS_URL must be HTTPS and AI_MODEL must be configured in production.';
        }

        if ($this->fallbackEnabled && !$this->providerConfigIsComplete(
            $this->fallbackUrl,
            $this->fallbackApiKey,
            $this->fallbackModel,
        )) {
            $issues[] = 'Enabled AI fallback requires HTTPS URL, API key, and model.';
        }

        if ($this->fallback2Enabled && !$this->providerConfigIsComplete(
            $this->fallback2Url,
            $this->fallback2ApiKey,
            $this->fallback2Model,
        )) {
            $issues[] = 'Enabled AI fallback 2 requires HTTPS URL, API key, and model.';
        }

        $effectiveModerationKey = trim($this->moderationApiKey) !== ''
            ? $this->moderationApiKey
            : $this->fallbackApiKey;
        if (!$this->isHttpsUrl($this->moderationUrl)
            || trim($effectiveModerationKey) === ''
            || trim($this->moderationModel) === ''
        ) {
            $issues[] = 'AI moderation requires an HTTPS URL, model, and dedicated or OpenAI fallback API key.';
        }

        if ($this->moderationTimeoutSeconds < 1 || $this->moderationTimeoutSeconds > 10) {
            $issues[] = 'AI_MODERATION_TIMEOUT_SECONDS must be between 1 and 10.';
        }

        return $issues;
    }

    private function providerConfigIsComplete(string $url, string $apiKey, string $model): bool
    {
        return $this->isHttpsUrl($url)
            && trim($apiKey) !== ''
            && trim($model) !== '';
    }

    private function isHttpsUrl(string $url): bool
    {
        $parts = parse_url(trim($url));

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && trim((string) ($parts['host'] ?? '')) !== '';
    }

    public function assertReady(): void
    {
        $issues = $this->issues();
        if ($issues === []) {
            return;
        }

        throw new \RuntimeException('Production configuration is not ready: ' . implode(' ', $issues));
    }
}
