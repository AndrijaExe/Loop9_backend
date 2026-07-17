<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

use App\Domain\Chat\Policy\ProviderRoutingPolicy;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * @phpstan-type ProviderConfig array{
 *   label: string,
 *   tier: string,
 *   url: string,
 *   apiKey: string,
 *   model: string,
 *   verifyTls: bool
 * }
 */
final class AiProviderCatalog
{
    public function __construct(
        #[Autowire(env: 'APP_ENV')]
        private readonly string $appEnv,
        #[Autowire(env: 'AI_CHAT_COMPLETIONS_URL')]
        private readonly string $primaryUrl,
        #[Autowire(env: 'AI_API_KEY')]
        private readonly string $primaryApiKey,
        #[Autowire(env: 'AI_MODEL')]
        private readonly string $primaryModel,
        #[Autowire(env: 'AI_TLS_VERIFY')]
        private readonly string $primaryTlsVerify,
        #[Autowire(env: 'AI_FALLBACK_ENABLED')]
        private readonly string $fallbackEnabled,
        #[Autowire(env: 'AI_FALLBACK_CHAT_COMPLETIONS_URL')]
        private readonly string $fallbackUrl,
        #[Autowire(env: 'AI_FALLBACK_API_KEY')]
        private readonly string $fallbackApiKey,
        #[Autowire(env: 'AI_FALLBACK_MODEL')]
        private readonly string $fallbackModel,
        #[Autowire(env: 'AI_FALLBACK_TLS_VERIFY')]
        private readonly string $fallbackTlsVerify,
        #[Autowire(env: 'AI_FALLBACK2_ENABLED')]
        private readonly string $fallback2Enabled,
        #[Autowire(env: 'AI_FALLBACK2_CHAT_COMPLETIONS_URL')]
        private readonly string $fallback2Url,
        #[Autowire(env: 'AI_FALLBACK2_API_KEY')]
        private readonly string $fallback2ApiKey,
        #[Autowire(env: 'AI_FALLBACK2_MODEL')]
        private readonly string $fallback2Model,
        #[Autowire(env: 'AI_FALLBACK2_TLS_VERIFY')]
        private readonly string $fallback2TlsVerify,
    ) {
    }

    /**
     * @return list<ProviderConfig>
     */
    public function configuredProviders(): array
    {
        if (trim($this->primaryApiKey) === '') {
            throw new \RuntimeException('AI API key is not configured.');
        }

        $providers = [
            [
                'label' => 'primary',
                'tier' => ProviderRoutingPolicy::TIER_BEST,
                'url' => $this->primaryUrl,
                'apiKey' => $this->primaryApiKey,
                'model' => $this->primaryModel,
                'verifyTls' => $this->resolveTlsVerify($this->primaryTlsVerify),
            ],
        ];

        if ($this->canUseFallbackLevel1()) {
            $providers[] = [
                'label' => 'fallback1',
                'tier' => ProviderRoutingPolicy::TIER_BALANCED,
                'url' => $this->fallbackUrl,
                'apiKey' => $this->fallbackApiKey,
                'model' => $this->fallbackModel,
                'verifyTls' => $this->resolveTlsVerify($this->fallbackTlsVerify),
            ];
        }

        if ($this->canUseFallbackLevel2()) {
            $providers[] = [
                'label' => 'fallback2',
                'tier' => ProviderRoutingPolicy::TIER_CHEAP,
                'url' => $this->fallback2Url,
                'apiKey' => $this->fallback2ApiKey,
                'model' => $this->fallback2Model,
                'verifyTls' => $this->resolveTlsVerify($this->fallback2TlsVerify),
            ];
        }

        return $providers;
    }

    private function canUseFallbackLevel1(): bool
    {
        return $this->isEnabled($this->fallbackEnabled)
            && trim($this->fallbackUrl) !== ''
            && trim($this->fallbackApiKey) !== ''
            && trim($this->fallbackModel) !== '';
    }

    private function canUseFallbackLevel2(): bool
    {
        return $this->isEnabled($this->fallback2Enabled)
            && trim($this->fallback2Url) !== ''
            && trim($this->fallback2ApiKey) !== ''
            && trim($this->fallback2Model) !== '';
    }

    private function resolveTlsVerify(string $value): bool
    {
        // Never allow disabling TLS verification in production.
        if ($this->appEnv === 'prod') {
            return true;
        }

        return $this->isEnabled($value);
    }

    private function isEnabled(string $value): bool
    {
        return !in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
    }
}
