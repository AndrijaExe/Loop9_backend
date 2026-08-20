<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model\Chat;

use App\Model\Chat\ProviderRoutingPolicy;
use PHPUnit\Framework\TestCase;

final class ProviderRoutingPolicyTest extends TestCase
{
    private ProviderRoutingPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new ProviderRoutingPolicy();
    }

    public function testEarlyLoopsPreferCheapProviders(): void
    {
        $ordered = $this->policy->orderByLoop($this->providers(), 2);

        self::assertSame(['fallback2', 'fallback1', 'primary'], array_column($ordered, 'label'));
    }

    public function testMidLoopsPreferBalancedProviders(): void
    {
        $ordered = $this->policy->orderByLoop($this->providers(), 5);

        self::assertSame(['fallback1', 'primary', 'fallback2'], array_column($ordered, 'label'));
    }

    public function testLateLoopsPreferBestProviders(): void
    {
        $ordered = $this->policy->orderByLoop($this->providers(), 8);

        self::assertSame(['primary', 'fallback1', 'fallback2'], array_column($ordered, 'label'));
    }

    /**
     * The shipped configuration has no balanced tier, so this pins the contract
     * the models were chosen for: luna answers the first three loops and terra
     * answers the rest, each covering the other on a broken reply.
     */
    public function testShippedTwoProviderShapeGivesLunaTheEarlyLoopsAndTerraTheRest(): void
    {
        $shipped = [
            ['label' => 'primary', 'tier' => ProviderRoutingPolicy::TIER_BEST, 'url' => 'a', 'model' => 'gpt-5.6-terra'],
            ['label' => 'fallback2', 'tier' => ProviderRoutingPolicy::TIER_CHEAP, 'url' => 'b', 'model' => 'gpt-5.6-luna'],
        ];

        foreach ([1, 2, 3] as $earlyLoop) {
            self::assertSame(
                ['gpt-5.6-luna', 'gpt-5.6-terra'],
                array_column($this->policy->orderByLoop($shipped, $earlyLoop), 'model'),
                sprintf('Loop %d must open on luna.', $earlyLoop),
            );
        }

        foreach ([4, 6, 7, 9] as $laterLoop) {
            self::assertSame(
                ['gpt-5.6-terra', 'gpt-5.6-luna'],
                array_column($this->policy->orderByLoop($shipped, $laterLoop), 'model'),
                sprintf('Loop %d must open on terra.', $laterLoop),
            );
        }
    }

    public function testMaxTokensScaleWithLoop(): void
    {
        self::assertSame(90, $this->policy->maxTokensForLoop(1));
        self::assertSame(130, $this->policy->maxTokensForLoop(5));
        self::assertSame(180, $this->policy->maxTokensForLoop(9));
    }

    /**
     * @return list<array{label: string, tier: string, url: string, model: string}>
     */
    private function providers(): array
    {
        return [
            ['label' => 'primary', 'tier' => ProviderRoutingPolicy::TIER_BEST, 'url' => 'a', 'model' => 'm1'],
            ['label' => 'fallback1', 'tier' => ProviderRoutingPolicy::TIER_BALANCED, 'url' => 'b', 'model' => 'm2'],
            ['label' => 'fallback2', 'tier' => ProviderRoutingPolicy::TIER_CHEAP, 'url' => 'c', 'model' => 'm3'],
        ];
    }
}
