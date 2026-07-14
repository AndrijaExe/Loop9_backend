<?php

declare(strict_types=1);

namespace App\Infrastructure\AI;

final class CostEstimator
{
    /** @var array<string, array{in: float, out: float}> */
    private const RATES = [
        'gpt-5.4' => ['in' => 2.5, 'out' => 15.0],
        'gpt-5.4-mini' => ['in' => 0.75, 'out' => 4.5],
        'gpt-5.4-nano' => ['in' => 0.2, 'out' => 1.25],
        'llama-3.3-70b-versatile' => ['in' => 0.59, 'out' => 0.79],
        'gemini-2.0-flash' => ['in' => 0.10, 'out' => 0.40],
    ];

    public function estimateUsd(string $model, ?int $promptTokens, ?int $completionTokens): ?float
    {
        if ($promptTokens === null || $completionTokens === null || !isset(self::RATES[$model])) {
            return null;
        }

        $inputCost = ($promptTokens / 1_000_000) * self::RATES[$model]['in'];
        $outputCost = ($completionTokens / 1_000_000) * self::RATES[$model]['out'];

        return round($inputCost + $outputCost, 6);
    }
}
