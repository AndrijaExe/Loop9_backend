<?php

declare(strict_types=1);

namespace App\Adapter\Auth;

final class SteamVerificationUnavailableException extends \RuntimeException
{
    public const REASON_NOT_CONFIGURED = 'not_configured';
    public const REASON_TRANSPORT = 'transport_error';
    public const REASON_UPSTREAM_STATUS = 'upstream_status';
    public const REASON_INVALID_RESPONSE = 'invalid_response';

    public function __construct(
        public readonly string $reason,
        public readonly ?int $upstreamStatusCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('Steam ticket verification is unavailable.', previous: $previous);
    }
}
