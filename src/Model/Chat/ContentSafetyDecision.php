<?php

declare(strict_types=1);

namespace App\Model\Chat;

final class ContentSafetyDecision
{
    private function __construct(
        private readonly bool $safe,
        private readonly string $reason,
    ) {
    }

    public static function safe(): self
    {
        return new self(true, 'allowed');
    }

    public static function blocked(string $reason): self
    {
        return new self(false, $reason);
    }

    public function isSafe(): bool
    {
        return $this->safe;
    }

    public function reason(): string
    {
        return $this->reason;
    }
}
