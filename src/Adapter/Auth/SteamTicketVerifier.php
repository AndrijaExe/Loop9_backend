<?php

declare(strict_types=1);

namespace App\Adapter\Auth;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifies Steam auth session tickets via the Steam Web API
 * (ISteamUserAuth/AuthenticateUserTicket). Requires a publisher Web API key.
 */
final class SteamTicketVerifier
{
    private const VERIFY_URL = 'https://api.steampowered.com/ISteamUserAuth/AuthenticateUserTicket/v1/';
    private const TICKET_IDENTITY = 'Loop9';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
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

    public function verify(string $ticketHex): SteamTicketVerificationResult
    {
        if (!$this->isConfigured()) {
            throw new SteamVerificationUnavailableException(
                SteamVerificationUnavailableException::REASON_NOT_CONFIGURED,
            );
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
        } catch (\Throwable $e) {
            throw new SteamVerificationUnavailableException(
                SteamVerificationUnavailableException::REASON_TRANSPORT,
                previous: $e,
            );
        }

        if ($statusCode !== 200) {
            throw new SteamVerificationUnavailableException(
                SteamVerificationUnavailableException::REASON_UPSTREAM_STATUS,
                upstreamStatusCode: $statusCode,
            );
        }

        try {
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new SteamVerificationUnavailableException(
                SteamVerificationUnavailableException::REASON_INVALID_RESPONSE,
                upstreamStatusCode: $statusCode,
                previous: $e,
            );
        }

        $responseData = $data['response'] ?? null;
        if (!is_array($responseData)) {
            throw new SteamVerificationUnavailableException(
                SteamVerificationUnavailableException::REASON_INVALID_RESPONSE,
                upstreamStatusCode: $statusCode,
            );
        }

        $error = $responseData['error'] ?? null;
        if ($this->isRecognizedTicketRejection($error)) {
            return SteamTicketVerificationResult::rejected();
        }

        $params = $responseData['params'] ?? null;
        if (!is_array($params)
            || ($params['result'] ?? null) !== 'OK'
            || !array_key_exists('publisherbanned', $params)
            || !is_bool($params['publisherbanned'])) {
            throw new SteamVerificationUnavailableException(
                SteamVerificationUnavailableException::REASON_INVALID_RESPONSE,
                upstreamStatusCode: $statusCode,
            );
        }

        if ($params['publisherbanned']) {
            return SteamTicketVerificationResult::rejected();
        }

        $steamId = $params['steamid'] ?? null;

        if (!is_string($steamId) || !preg_match('/^\d{5,20}$/', $steamId)) {
            throw new SteamVerificationUnavailableException(
                SteamVerificationUnavailableException::REASON_INVALID_RESPONSE,
                upstreamStatusCode: $statusCode,
            );
        }

        return SteamTicketVerificationResult::accepted($steamId);
    }

    private function isRecognizedTicketRejection(mixed $error): bool
    {
        if (!is_array($error)) {
            return false;
        }

        $errorCode = $error['errorcode'] ?? null;
        $description = $error['errordesc'] ?? null;

        return (is_int($errorCode)
                || (is_string($errorCode) && preg_match('/\A\d{1,10}\z/', $errorCode) === 1))
            && is_string($description)
            && trim($description) !== '';
    }
}
