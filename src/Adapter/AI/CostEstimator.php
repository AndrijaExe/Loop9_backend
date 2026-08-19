<?php

declare(strict_types=1);

namespace App\Adapter\AI;

/**
 * Standard short-context list prices, USD per million tokens.
 *
 * OpenAI 5.6 rates are the public list after the 2026-07-30 cut
 * (https://developers.openai.com/api/docs/pricing). Cached input is billed
 * cheaper when the provider reports it; otherwise the whole prompt is charged
 * at the uncached input rate.
 *
 * @phpstan-type Rate array{in: float, out: float, cached?: float}
 */
final class CostEstimator
{
    /** @var array<string, Rate> */
    private const RATES = [
        'gpt-5.4' => ['in' => 2.5, 'cached' => 0.25, 'out' => 15.0],
        'gpt-5.4-mini' => ['in' => 0.75, 'cached' => 0.075, 'out' => 4.5],
        'gpt-5.4-nano' => ['in' => 0.2, 'cached' => 0.02, 'out' => 1.25],
        'gpt-5.6-terra' => ['in' => 2.0, 'cached' => 0.20, 'out' => 12.0],
        'gpt-5.6-luna' => ['in' => 0.20, 'cached' => 0.02, 'out' => 1.20],
        'gpt-5.6-sol' => ['in' => 5.0, 'cached' => 0.50, 'out' => 30.0],
        'llama-3.3-70b-versatile' => ['in' => 0.59, 'out' => 0.79],
        'openai/gpt-oss-120b' => ['in' => 0.15, 'cached' => 0.075, 'out' => 0.60],
        'gemini-2.0-flash' => ['in' => 0.10, 'out' => 0.40],
    ];

    public function estimateUsd(
        string $model,
        ?int $promptTokens,
        ?int $completionTokens,
        ?int $cachedTokens = null,
    ): ?float {
        if ($promptTokens === null || $completionTokens === null || !isset(self::RATES[$model])) {
            return null;
        }

        $rate = self::RATES[$model];
        $cached = max(0, min($promptTokens, $cachedTokens ?? 0));
        $uncached = $promptTokens - $cached;
        $cachedRate = $rate['cached'] ?? $rate['in'];

        $inputCost = ($uncached / 1_000_000) * $rate['in'];
        $cachedCost = ($cached / 1_000_000) * $cachedRate;
        $outputCost = ($completionTokens / 1_000_000) * $rate['out'];

        return round($inputCost + $cachedCost + $outputCost, 6);
    }
}
