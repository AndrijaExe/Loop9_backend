<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Config;

use App\Infrastructure\Config\ProductionConfigValidator;
use PHPUnit\Framework\TestCase;

final class ProductionConfigValidatorTest extends TestCase
{
    public function testIgnoresIssuesOutsideProduction(): void
    {
        $validator = $this->makeValidator(appEnv: 'dev', trustedProxies: '');

        self::assertSame([], $validator->issues());
    }

    public function testReportsMissingProductionSettings(): void
    {
        $validator = $this->makeValidator(
            appEnv: 'prod',
            trustedProxies: '',
            redisUrl: '',
            sessionTokenSecret: 'short',
            sessionTokenTtl: 120,
            steamWebApiKey: '',
            steamAppId: '',
            allowGameToken: true,
            gameApiToken: 'change-this-token',
            aiTlsVerify: false,
            aiApiKey: '',
            aiUrl: '',
            aiModel: '',
            fallbackEnabled: true,
            fallbackUrl: '',
            fallbackApiKey: '',
            fallbackModel: '',
            moderationUrl: '',
            moderationApiKey: '',
            moderationModel: '',
            moderationTimeoutSeconds: 0,
        );

        $issues = $validator->issues();

        self::assertNotSame([], $issues);
        self::assertStringContainsString('TRUSTED_PROXIES', implode(' ', $issues));
        self::assertStringContainsString('REDIS_URL', implode(' ', $issues));
        self::assertStringContainsString('SESSION_TOKEN_SECRET', implode(' ', $issues));
        self::assertStringContainsString('SESSION_TOKEN_TTL', implode(' ', $issues));
        self::assertStringContainsString('STEAM_', implode(' ', $issues));
        self::assertStringContainsString('AUTH_ALLOW_GAME_TOKEN', implode(' ', $issues));
        self::assertStringContainsString('AI_TLS_VERIFY', implode(' ', $issues));
        self::assertStringContainsString('AI_API_KEY', implode(' ', $issues));
        self::assertStringContainsString('AI_CHAT_COMPLETIONS_URL', implode(' ', $issues));
        self::assertStringContainsString('fallback', implode(' ', $issues));
        self::assertStringContainsString('moderation', implode(' ', $issues));
        self::assertStringContainsString('AI_MODERATION_TIMEOUT_SECONDS', implode(' ', $issues));
    }

    public function testAcceptsHardenedProductionConfig(): void
    {
        $validator = $this->makeValidator(
            appEnv: 'prod',
            trustedProxies: '127.0.0.1,REMOTE_ADDR',
            redisUrl: 'redis://localhost:6379',
            sessionTokenSecret: str_repeat('a', 32),
            sessionTokenTtl: 43200,
            steamWebApiKey: 'steam-key',
            steamAppId: '480',
            allowGameToken: false,
            gameApiToken: '',
            aiTlsVerify: true,
            aiApiKey: 'ai-key',
        );

        self::assertSame([], $validator->issues());
    }

    private function makeValidator(
        string $appEnv,
        string $trustedProxies = '127.0.0.1,REMOTE_ADDR',
        string $redisUrl = 'redis://localhost:6379',
        string $sessionTokenSecret = '0123456789abcdef0123456789abcdef',
        int $sessionTokenTtl = 43200,
        string $steamWebApiKey = 'key',
        string $steamAppId = '480',
        bool $allowGameToken = false,
        string $gameApiToken = '',
        bool $aiTlsVerify = true,
        string $aiApiKey = 'ai-key',
        string $aiUrl = 'https://api.example.test/v1/chat/completions',
        string $aiModel = 'model',
        bool $fallbackEnabled = false,
        string $fallbackUrl = '',
        string $fallbackApiKey = '',
        string $fallbackModel = '',
        bool $fallback2Enabled = false,
        string $fallback2Url = '',
        string $fallback2ApiKey = '',
        string $fallback2Model = '',
        string $moderationUrl = 'https://api.openai.com/v1/moderations',
        string $moderationApiKey = 'moderation-key',
        string $moderationModel = 'omni-moderation-latest',
        int $moderationTimeoutSeconds = 3,
    ): ProductionConfigValidator {
        return new ProductionConfigValidator(
            appEnv: $appEnv,
            trustedProxies: $trustedProxies,
            redisUrl: $redisUrl,
            sessionTokenSecret: $sessionTokenSecret,
            sessionTokenTtl: $sessionTokenTtl,
            steamWebApiKey: $steamWebApiKey,
            steamAppId: $steamAppId,
            allowGameToken: $allowGameToken,
            gameApiToken: $gameApiToken,
            aiTlsVerify: $aiTlsVerify,
            aiApiKey: $aiApiKey,
            aiUrl: $aiUrl,
            aiModel: $aiModel,
            fallbackEnabled: $fallbackEnabled,
            fallbackUrl: $fallbackUrl,
            fallbackApiKey: $fallbackApiKey,
            fallbackModel: $fallbackModel,
            fallback2Enabled: $fallback2Enabled,
            fallback2Url: $fallback2Url,
            fallback2ApiKey: $fallback2ApiKey,
            fallback2Model: $fallback2Model,
            moderationUrl: $moderationUrl,
            moderationApiKey: $moderationApiKey,
            moderationModel: $moderationModel,
            moderationTimeoutSeconds: $moderationTimeoutSeconds,
        );
    }
}
