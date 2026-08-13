<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Application\ChatService;
use App\Model\Telemetry\Event;
use App\Model\Telemetry\EventCounters;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class ChatController
{
    public function __construct(
        private readonly GameTokenAuthenticator $tokenAuthenticator,
        private readonly ChatRateLimiter $rateLimiter,
        private readonly ChatRequestMapper $requestMapper,
        private readonly ChatService $sendChatMessage,
        private readonly LoggerInterface $logger,
        private readonly EventCounters $counters,
    ) {
    }

    #[Route('/api/chat', name: 'game_chat', methods: ['POST', 'OPTIONS'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        $startedAt = hrtime(true);
        $requestId = RequestMonitor::requestId($request);

        $authStartedAt = hrtime(true);
        $auth = $this->tokenAuthenticator->authenticate($request);
        $authMs = RequestMonitor::elapsedMs($authStartedAt);

        // Cheap burst protection first — does not consume fair-use / cost quotas.
        $burstLimitStartedAt = hrtime(true);
        if ($denied = $this->rateLimiter->enforceIpLimit($auth->scope, $request)) {
            return $this->refuse($denied);
        }
        $burstLimitMs = RequestMonitor::elapsedMs($burstLimitStartedAt);

        // Validate the body before spending daily/global/player quotas so
        // malformed requests cannot burn the AI spend kill-switch.
        $validationStartedAt = hrtime(true);
        $mapped = $this->requestMapper->map($request);
        $validationMs = RequestMonitor::elapsedMs($validationStartedAt);

        // Session-token auth carries a verified identity; never trust the
        // client-supplied player_id in that case.
        $quotaStartedAt = hrtime(true);
        $playerId = $auth->playerId ?? $this->rateLimiter->resolvePlayerId($mapped['payload'], $request);

        if ($denied = $this->rateLimiter->enforceIpDailyQuota($auth->scope, $request)) {
            return $this->refuse($denied);
        }

        if ($denied = $this->rateLimiter->enforcePlayerDailyQuota($playerId)) {
            return $this->refuse($denied);
        }

        if ($denied = $this->rateLimiter->enforcePlayerMonthlyQuota($playerId)) {
            return $this->refuse($denied);
        }

        // Consume the shared cost kill-switch last so callers already denied
        // by a narrower quota cannot drain allowance for every player.
        if ($denied = $this->rateLimiter->enforceGlobalDailyQuota()) {
            return $this->refuse($denied);
        }
        $quotaMs = RequestMonitor::elapsedMs($quotaStartedAt);

        $aiStartedAt = hrtime(true);
        $response = ($this->sendChatMessage)($mapped['message'], $mapped['context']);
        $aiMs = RequestMonitor::elapsedMs($aiStartedAt);
        $totalMs = RequestMonitor::elapsedMs($startedAt);

        $this->logger->info('Game chat message processed.', [
            'requestId' => $requestId,
            'ipHash' => hash('sha256', $request->getClientIp() ?? 'unknown'),
            'playerIdHash' => hash('sha256', $playerId),
            'loopIndex' => $mapped['context']->loopIndex(),
            'timingMs' => [
                'auth' => $authMs,
                'burstLimit' => $burstLimitMs,
                'validation' => $validationMs,
                'quotas' => $quotaMs,
                'ai' => $aiMs,
                'total' => $totalMs,
            ],
        ]);

        $this->counters->increment(Event::CHAT_MESSAGES);

        return new JsonResponse($response->toArray(), 200, [
            'X-Request-Id' => $requestId,
        ]);
    }

    /**
     * Every quota lands here so a refusal is counted once, whichever limit ran out.
     */
    private function refuse(JsonResponse $denied): JsonResponse
    {
        $this->counters->increment(Event::CHAT_DENIED);

        return $denied;
    }
}
