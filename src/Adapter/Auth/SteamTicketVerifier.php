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
    private const VERIFY_URL = 'https://api.steampowered.com/ISteamUserAuth/AuthenticateUserTicket/v1/';

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
                'query' => [
                    'key' => $this->webApiKey,
                    'appid' => $this->appId,
                    'ticket' => $ticketHex,
                ],
                'timeout' => 10,
            ]);

            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Steam ticket verification request failed.', [
                'exception' => $e->getMessage(),
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
