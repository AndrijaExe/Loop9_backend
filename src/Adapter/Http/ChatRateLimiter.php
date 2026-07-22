<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class ChatRateLimiter
{
    public const MIN_PLAYER_ID_LENGTH = 8;
    public const MAX_PLAYER_ID_LENGTH = 128;

    public function __construct(
        #[Autowire(service: 'limiter.game_chat')]
        private readonly RateLimiterFactory $ipRateLimiterFactory,
        #[Autowire(service: 'limiter.game_ip_daily')]
        private readonly RateLimiterFactory $ipDailyQuotaLimiterFactory,
        #[Autowire(service: 'limiter.player_daily_quota')]
        private readonly RateLimiterFactory $playerDailyQuotaLimiterFactory,
        #[Autowire(service: 'limiter.player_monthly_quota')]
        private readonly RateLimiterFactory $playerMonthlyQuotaLimiterFactory,
        #[Autowire(service: 'limiter.game_global_daily')]
        private readonly RateLimiterFactory $globalDailyLimiterFactory,
    ) {
    }

    public function enforceGlobalDailyQuota(): ?JsonResponse
    {
        $rateLimit = $this->globalDailyLimiterFactory->create('global')->consume(1);

        if ($rateLimit->isAccepted()) {
            return null;
        }

        return $this->tooManyRequests(
            'Service is at capacity, try again later.',
            'GLOBAL_DAILY_QUOTA_EXCEEDED',
            $rateLimit->getRetryAfter(),
            86400,
        );
    }

    public function enforceIpLimit(string $authScope, Request $request): ?JsonResponse
    {
        $ip = $request->getClientIp() ?? 'unknown';
        $key = hash('sha256', $authScope . '|' . $ip);
        $rateLimit = $this->ipRateLimiterFactory->create($key)->consume(1);

        if ($rateLimit->isAccepted()) {
            return null;
        }

        return $this->tooManyRequests(
            'Too many requests.',
            'RATE_LIMITED',
            $rateLimit->getRetryAfter(),
            60,
        );
    }

    public function enforceIpDailyQuota(string $authScope, Request $request): ?JsonResponse
    {
        $ip = $request->getClientIp() ?? 'unknown';
        $key = hash('sha256', 'ip-daily|' . $authScope . '|' . $ip);
        $rateLimit = $this->ipDailyQuotaLimiterFactory->create($key)->consume(1);

        if ($rateLimit->isAccepted()) {
            return null;
        }

        return $this->tooManyRequests(
            'Daily IP quota exceeded.',
            'IP_DAILY_QUOTA_EXCEEDED',
            $rateLimit->getRetryAfter(),
            86400,
        );
    }

    public function enforcePlayerDailyQuota(string $playerId): ?JsonResponse
    {
        $rateLimit = $this->playerDailyQuotaLimiterFactory
            ->create(hash('sha256', 'player-daily|' . trim($playerId)))
            ->consume(1);

        if ($rateLimit->isAccepted()) {
            return null;
        }

        return $this->tooManyRequests(
            'Daily player quota exceeded.',
            'PLAYER_DAILY_QUOTA_EXCEEDED',
            $rateLimit->getRetryAfter(),
            86400,
        );
    }

    public function enforcePlayerMonthlyQuota(string $playerId): ?JsonResponse
    {
        $rateLimit = $this->playerMonthlyQuotaLimiterFactory
            ->create(hash('sha256', 'player-monthly|' . trim($playerId)))
            ->consume(1);

        if ($rateLimit->isAccepted()) {
            return null;
        }

        return $this->tooManyRequests(
            'Monthly player quota exceeded.',
            'PLAYER_MONTHLY_QUOTA_EXCEEDED',
            $rateLimit->getRetryAfter(),
            86400 * 30,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function resolvePlayerId(array $payload, Request $request): string
    {
        $playerId = $payload['player_id'] ?? $request->headers->get('X-Player-Id');

        if (!is_string($playerId)) {
            throw new BadRequestHttpException('Field "player_id" (or header "X-Player-Id") is required for quota tracking.');
        }

        $playerId = trim($playerId);

        if ($playerId === '') {
            throw new BadRequestHttpException('Field "player_id" (or header "X-Player-Id") is required for quota tracking.');
        }

        $length = mb_strlen($playerId);
        if ($length < self::MIN_PLAYER_ID_LENGTH || $length > self::MAX_PLAYER_ID_LENGTH) {
            throw new BadRequestHttpException(sprintf(
                'Field "player_id" must be between %d and %d characters.',
                self::MIN_PLAYER_ID_LENGTH,
                self::MAX_PLAYER_ID_LENGTH
            ));
        }

        if (!preg_match('/^[A-Za-z0-9._:-]+$/', $playerId)) {
            throw new BadRequestHttpException('Field "player_id" contains invalid characters.');
        }

        return $playerId;
    }

    private function tooManyRequests(
        string $message,
        string $code,
        ?\DateTimeInterface $retryAfter,
        int $fallbackSeconds,
    ): JsonResponse {
        $retryAfterSeconds = $retryAfter === null
            ? $fallbackSeconds
            : max(1, $retryAfter->getTimestamp() - time());

        return new JsonResponse([
            'error' => [
                'message' => $message,
                'code' => $code,
            ],
        ], 429, [
            'Retry-After' => (string) $retryAfterSeconds,
        ]);
    }
}
