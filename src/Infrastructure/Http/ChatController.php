<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Application\Chat\SendChatMessageHandler;
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
        private readonly SendChatMessageHandler $sendChatMessage,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/chat', name: 'game_chat', methods: ['POST', 'OPTIONS'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        $token = $this->tokenAuthenticator->authenticate($request);

        if ($denied = $this->rateLimiter->enforceGlobalDailyQuota()) {
            return $denied;
        }

        if ($denied = $this->rateLimiter->enforceIpLimit($token, $request)) {
            return $denied;
        }

        if ($denied = $this->rateLimiter->enforceIpDailyQuota($token, $request)) {
            return $denied;
        }

        $mapped = $this->requestMapper->map($request);
        $playerId = $this->rateLimiter->resolvePlayerId($mapped['payload'], $request);

        if ($denied = $this->rateLimiter->enforcePlayerDailyQuota($playerId)) {
            return $denied;
        }

        if ($denied = $this->rateLimiter->enforcePlayerMonthlyQuota($playerId)) {
            return $denied;
        }

        $response = ($this->sendChatMessage)($mapped['message'], $mapped['context']);

        $this->logger->info('Game chat message processed.', [
            'ip' => $request->getClientIp() ?? 'unknown',
            'playerIdHash' => hash('sha256', $playerId),
            'loopIndex' => $mapped['context']->loopIndex(),
        ]);

        return new JsonResponse($response->toArray(), 200);
    }
}
