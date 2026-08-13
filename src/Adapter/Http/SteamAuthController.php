<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use App\Adapter\Auth\SessionTokenIssuer;
use App\Adapter\Auth\SteamTicketVerifier;
use App\Adapter\Auth\SteamVerificationUnavailableException;
use App\Model\Telemetry\Event;
use App\Model\Telemetry\EventCounters;
use App\Model\Telemetry\PlayerPresence;
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
    /** Steam's 2560-byte Web API ticket encoded as hexadecimal. */
    public const MAX_TICKET_LENGTH = 5120;

    public function __construct(
        private readonly SteamTicketVerifier $steamTickets,
        private readonly SessionTokenIssuer $sessionTokens,
        #[Autowire(service: 'limiter.auth_steam')]
        private readonly RateLimiterFactory $authLimiterFactory,
        private readonly LoggerInterface $logger,
        private readonly EventCounters $counters,
        private readonly PlayerPresence $presence,
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
        try {
            $verification = $this->steamTickets->verify($ticket);
        } catch (SteamVerificationUnavailableException $exception) {
            $steamVerifyMs = RequestMonitor::elapsedMs($steamVerifyStartedAt);
            $this->logger->error('Steam ticket verification unavailable.', [
                'requestId' => $requestId,
                'ipHash' => hash('sha256', $ip),
                'exceptionClass' => $exception::class,
                'reason' => $exception->reason,
                'upstreamStatusCode' => $exception->upstreamStatusCode,
                'timingMs' => [
                    'rateLimit' => $rateLimitMs,
                    'ticketValidation' => $ticketValidationMs,
                    'steamVerify' => $steamVerifyMs,
                    'total' => RequestMonitor::elapsedMs($startedAt),
                ],
            ]);

            return new JsonResponse([
                'error' => [
                    'message' => 'Steam ticket verification is temporarily unavailable.',
                    'code' => 'STEAM_UPSTREAM_UNAVAILABLE',
                ],
            ], 503, [
                'X-Request-Id' => $requestId,
            ]);
        }
        $steamVerifyMs = RequestMonitor::elapsedMs($steamVerifyStartedAt);

        if (!$verification->accepted) {
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

            $this->counters->increment(Event::AUTH_REJECTED);

            return new JsonResponse([
                'error' => ['message' => 'Steam ticket rejected.', 'code' => 'STEAM_TICKET_INVALID'],
            ], 403, [
                'X-Request-Id' => $requestId,
            ]);
        }

        $steamId = $verification->steamId;
        if ($steamId === null) {
            throw new \LogicException('Accepted Steam verification must contain a Steam ID.');
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

        $this->counters->increment(Event::AUTH_ISSUED);
        // A login is the first sign of a player, and often the only one for a while: a run can
        // go a long time without a chat message.
        $this->presence->seen($playerId);

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
