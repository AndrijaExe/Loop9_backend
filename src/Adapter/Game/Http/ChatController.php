<?php

declare(strict_types=1);

namespace App\Adapter\Game\Http;

use App\Application\SendToAi;
use App\Application\SendToGameClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class ChatController
{
    public function __construct(
        private readonly SendToAi $sendToAi,
        private readonly SendToGameClient $sendToGameClient,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.game_chat')]
        private readonly RateLimiterFactory $rateLimiterFactory,
        #[Autowire(service: 'limiter.player_daily_quota')]
        private readonly RateLimiterFactory $playerDailyQuotaLimiterFactory,
        #[Autowire(env: 'GAME_API_TOKEN')]
        private readonly string $gameApiToken,
    ) {
    }

    #[Route('/api/chat', name: 'game_chat', methods: ['POST', 'OPTIONS'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        $token = (string) $request->headers->get('X-Game-Token', '');

        if ($this->gameApiToken === '') {
            throw new \RuntimeException('GAME_API_TOKEN is not configured.');
        }

        if (!hash_equals($this->gameApiToken, $token)) {
            throw new AccessDeniedHttpException('Invalid token.');
        }

        $ip = $request->getClientIp() ?? 'unknown';
        $key = hash('sha256', $token . '|' . $ip);
        $rateLimit = $this->rateLimiterFactory->create($key)->consume(1);

        if (!$rateLimit->isAccepted()) {
            $retryAfter = $rateLimit->getRetryAfter();
            $retryAfterSeconds = $retryAfter === null ? 60 : max(1, $retryAfter->getTimestamp() - time());

            return new JsonResponse([
                'error' => [
                    'message' => 'Too many requests.',
                    'code' => 'RATE_LIMITED',
                ],
            ], 429, [
                'Retry-After' => (string) $retryAfterSeconds,
            ]);
        }

        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }

        $playerMessage = $payload['message'] ?? null;

        if (!is_string($playerMessage)) {
            throw new BadRequestHttpException('Field "message" must be a string.');
        }

        $playerId = $payload['player_id'] ?? $request->headers->get('X-Player-Id');

        if (!is_string($playerId) || trim($playerId) === '') {
            throw new BadRequestHttpException('Field "player_id" (or header "X-Player-Id") is required for daily quota tracking.');
        }

        $playerQuotaLimit = $this->playerDailyQuotaLimiterFactory
            ->create(hash('sha256', trim($playerId)))
            ->consume(1);

        if (!$playerQuotaLimit->isAccepted()) {
            $retryAfter = $playerQuotaLimit->getRetryAfter();
            $retryAfterSeconds = $retryAfter === null ? 86400 : max(1, $retryAfter->getTimestamp() - time());

            return new JsonResponse([
                'error' => [
                    'message' => 'Daily player quota exceeded.',
                    'code' => 'PLAYER_DAILY_QUOTA_EXCEEDED',
                ],
            ], 429, [
                'Retry-After' => (string) $retryAfterSeconds,
            ]);
        }

        $runtimeContext = [
            'language' => $payload['language'] ?? null,
            'ai_stability' => $payload['ai_stability'] ?? null,
            'state' => $payload['state'] ?? null,
            'anomaly_context' => $payload['anomaly_context'] ?? null,
            'loop_index' => $payload['loop_index'] ?? null,
            'offtopic' => $payload['offtopic'] ?? null,
        ];

        $assistantMessage = ($this->sendToAi)($playerMessage, $runtimeContext);
        $responsePayload = ($this->sendToGameClient)($assistantMessage);

        $this->logger->info('Game chat message processed.', [
            'ip' => $ip,
        ]);

        return new JsonResponse($responsePayload, 200);
    }
}
