<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Infrastructure\Auth\SessionTokenIssuer;
use App\Infrastructure\Auth\SteamTicketVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Exchanges a Steam auth session ticket for a short-lived session token.
 * The session token is then sent as X-Session-Token on /api/chat.
 */
final class SteamAuthController
{
    public const MAX_TICKET_LENGTH = 4096;

    public function __construct(
        private readonly SteamTicketVerifier $steamTickets,
        private readonly SessionTokenIssuer $sessionTokens,
        #[Autowire(service: 'limiter.auth_steam')]
        private readonly RateLimiterFactory $authLimiterFactory,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/api/auth/steam', name: 'auth_steam', methods: ['POST', 'OPTIONS'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($request->isMethod('OPTIONS')) {
            return new JsonResponse(null, 204);
        }

        $startedAt = hrtime(true);
        $requestId = RequestMonitor::requestId($request);
        $ip = $request->getClientIp() ?? 'unknown';

        $rateLimitStartedAt = hrtime(true);
        $rateLimit = $this->authLimiterFactory->create(hash('sha256', 'auth|' . $ip))->consume(1);
        $rateLimitMs = RequestMonitor::elapsedMs($rateLimitStartedAt);

        if (!$rateLimit->isAccepted()) {
            return new JsonResponse([
                'error' => ['message' => 'Too many auth attempts.', 'code' => 'RATE_LIMITED'],
            ], 429);
        }

        if (!$this->steamTickets->isConfigured() || !$this->sessionTokens->isConfigured()) {
            return new JsonResponse([
                'error' => ['message' => 'Steam auth is not enabled on this server.', 'code' => 'STEAM_AUTH_DISABLED'],
            ], 503);
        }

        $ticketValidationStartedAt = hrtime(true);
        $ticket = $this->extractTicket($request);
        $ticketValidationMs = RequestMonitor::elapsedMs($ticketValidationStartedAt);

        $steamVerifyStartedAt = hrtime(true);
        $steamId = $this->steamTickets->verify($ticket);
        $steamVerifyMs = RequestMonitor::elapsedMs($steamVerifyStartedAt);

        if ($steamId === null) {
            $this->logger->warning('Steam ticket rejected.', [
                'requestId' => $requestId,
                'ipHash' => hash('sha256', $ip),
                'timingMs' => [
                    'rateLimit' => $rateLimitMs,
                    'ticketValidation' => $ticketValidationMs,
                    'steamVerify' => $steamVerifyMs,
                    'total' => RequestMonitor::elapsedMs($startedAt),
                ],
            ]);

            return new JsonResponse([
                'error' => ['message' => 'Steam ticket rejected.', 'code' => 'STEAM_TICKET_INVALID'],
            ], 403, [
                'X-Request-Id' => $requestId,
            ]);
        }

        $playerId = 'steam-' . $steamId;
        $tokenIssueStartedAt = hrtime(true);
        $issued = $this->sessionTokens->issue($playerId);
        $tokenIssueMs = RequestMonitor::elapsedMs($tokenIssueStartedAt);

        $this->logger->info('Steam session token issued.', [
            'requestId' => $requestId,
            'playerIdHash' => hash('sha256', $playerId),
            'ipHash' => hash('sha256', $ip),
            'timingMs' => [
                'rateLimit' => $rateLimitMs,
                'ticketValidation' => $ticketValidationMs,
                'steamVerify' => $steamVerifyMs,
                'tokenIssue' => $tokenIssueMs,
                'total' => RequestMonitor::elapsedMs($startedAt),
            ],
        ]);

        return new JsonResponse([
            'token' => $issued['token'],
            'expires_at' => $issued['expiresAt'],
            'player_id' => $playerId,
        ], 200, [
            'X-Request-Id' => $requestId,
        ]);
    }

    private function extractTicket(Request $request): string
    {
        try {
            $payload = json_decode($request->getContent(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Request body must be valid JSON.');
        }

        $ticket = is_array($payload) ? ($payload['ticket'] ?? null) : null;

        if (!is_string($ticket)) {
            throw new BadRequestHttpException('Field "ticket" is required.');
        }

        $ticket = trim($ticket);

        if ($ticket === '' || mb_strlen($ticket) > self::MAX_TICKET_LENGTH || !ctype_xdigit($ticket)) {
            throw new BadRequestHttpException('Field "ticket" must be a hex-encoded Steam session ticket.');
        }

        return $ticket;
    }
}
