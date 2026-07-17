<?php

declare(strict_types=1);

namespace App\Domain\Chat\Port;

use App\Domain\Chat\ContentSafetyDecision;

interface ContentSafetyGatewayInterface
{
    public const STAGE_INPUT = 'input';
    public const STAGE_OUTPUT = 'output';

    public function evaluate(string $text, string $stage): ContentSafetyDecision;
}
