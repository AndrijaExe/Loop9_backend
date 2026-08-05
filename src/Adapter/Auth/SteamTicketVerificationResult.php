<?php

declare(strict_types=1);

namespace App\Adapter\Auth;

final readonly class SteamTicketVerificationResult
{
    private function __construct(
        public bool $accepted,
        public ?string $steamId,
    ) {
    }

    public static function accepted(string $steamId): self
    {
        return new self(true, $steamId);
    }

    public static function rejected(): self
    {
        return new self(false, null);
    }
}
