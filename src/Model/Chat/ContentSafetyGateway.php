<?php

declare(strict_types=1);

namespace App\Model\Chat;

interface ContentSafetyGateway
{
    public const STAGE_INPUT = 'input';
    public const STAGE_OUTPUT = 'output';

    public function evaluate(string $text, string $stage): ContentSafetyDecision;
}
