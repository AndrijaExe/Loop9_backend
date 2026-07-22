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
