<?php

declare(strict_types=1);

namespace App\Model\Chat;

/**
 * Orders AI providers by loop index for cost vs quality tradeoffs.
 *
 * loops 1–3  => cheap → balanced → best
 * loops 4–6  => balanced → best → cheap
 * loops 7+   => best → balanced → cheap
 *
 * @template T of array{label: string, tier: string}
 */
final class ProviderRoutingPolicy
{
    public const TIER_BEST = 'best';
    public const TIER_BALANCED = 'balanced';
    public const TIER_CHEAP = 'cheap';

    /**
     * @param list<array{label: string, tier: string, ...}> $providers
     * @return list<array{label: string, tier: string, ...}>
     */
    public function orderByLoop(array $providers, int $loopIndex): array
    {
        $byTier = [];
        foreach ($providers as $provider) {
            $byTier[$provider['tier']][] = $provider;
        }

        $tierOrder = match (true) {
            $loopIndex <= 3 => [self::TIER_CHEAP, self::TIER_BALANCED, self::TIER_BEST],
            $loopIndex <= 6 => [self::TIER_BALANCED, self::TIER_BEST, self::TIER_CHEAP],
            default => [self::TIER_BEST, self::TIER_BALANCED, self::TIER_CHEAP],
        };

        $ordered = [];
        $seen = [];

        foreach ($tierOrder as $tier) {
            foreach ($byTier[$tier] ?? [] as $provider) {
                $signature = $provider['label'] . '|' . ($provider['url'] ?? '') . '|' . ($provider['model'] ?? '');
                if (isset($seen[$signature])) {
                    continue;
                }
                $seen[$signature] = true;
                $ordered[] = $provider;
            }
        }

        return $ordered;
    }

    public function maxTokensForLoop(int $loopIndex): int
    {
        return match (true) {
            $loopIndex <= 3 => 90,
            $loopIndex <= 6 => 130,
            default => 180,
        };
    }
}
