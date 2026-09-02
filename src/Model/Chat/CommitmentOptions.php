<?php

declare(strict_types=1);

namespace App\Model\Chat;

final readonly class CommitmentOptions
{
    public function __construct(
        public bool $masterEnabled,
        public bool $locationMisdirectionEnabled,
        public bool $wrongLiftEnabled,
    ) {
    }
}
