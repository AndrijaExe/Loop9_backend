<?php

declare(strict_types=1);

namespace App\Adapter\Auth;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifies Steam auth session tickets via the Steam Web API
 * (ISteamUserAuth/AuthenticateUserTicket). Requires a publisher Web API key.
 */
final class SteamTicketVerifier
{
    private const VERIFY_URL = 'https://partner.steam-api.com/ISteamUserAuth/AuthenticateUserTicket/v1/';
    private const TICKET_IDENTITY = 'Loop9';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'STEAM_WEB_API_KEY')]
        private readonly string $webApiKey,
        #[Autowire(env: 'STEAM_APP_ID')]
        private readonly string $appId,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->webApiKey !== '' && $this->appId !== '';
    }

    /**
     * Returns the SteamID64 when the ticket is valid, null otherwise.
     */
    public function verify(string $ticketHex): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', self::VERIFY_URL, [
                'headers' => [
                    'x-webapi-key' => $this->webApiKey,
                ],
                'query' => [
                    'appid' => $this->appId,
                    'ticket' => $ticketHex,
                    'identity' => self::TICKET_IDENTITY,
                ],
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 200) {
                $this->logger->error('Steam ticket verification upstream rejected request.', [
                    'statusCode' => $statusCode,
                ]);

                return null;
            }

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Steam ticket verification request failed.', [
                // Exception messages from HTTP clients can contain the complete
                // request URL. Never log them because the URL carries the ticket.
                'exceptionClass' => $e::class,
            ]);

            return null;
        }

        $params = $data['response']['params'] ?? null;

        if (!is_array($params) || ($params['result'] ?? null) !== 'OK') {
            $this->logger->info('Steam ticket rejected.', [
                'response' => $data['response'] ?? null,
            ]);

            return null;
        }

        $steamId = $params['steamid'] ?? null;

        if (!is_string($steamId) || !preg_match('/^\d{5,20}$/', $steamId)) {
            return null;
        }

        return $steamId;
    }
}
