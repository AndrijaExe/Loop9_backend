<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\AI;

use App\Adapter\AI\CostEstimator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CostEstimatorTest extends TestCase
{
    #[DataProvider('modelCosts')]
    public function testEstimatesCurrentListPrices(string $model, float $expectedUsd): void
    {
        self::assertSame(
            $expectedUsd,
            (new CostEstimator())->estimateUsd($model, 1_000, 100)
        );
    }

    /**
     * @return iterable<string, array{string, float}>
     */
    public static function modelCosts(): iterable
    {
        // 1000 in + 100 out at the public short-context list.
        yield 'GPT 5.6 Terra' => ['gpt-5.6-terra', 0.0032];
        yield 'GPT 5.6 Luna' => ['gpt-5.6-luna', 0.00032];
        yield 'GPT 5.6 Sol' => ['gpt-5.6-sol', 0.008];
        yield 'Groq GPT OSS 120B' => ['openai/gpt-oss-120b', 0.00021];
    }

    public function testCachedPromptTokensUseTheCachedInputRate(): void
    {
        // 800 cached + 200 fresh + 100 out on Terra: 800*0.20 + 200*2.00 + 100*12 / 1e6
        self::assertSame(
            0.00176,
            (new CostEstimator())->estimateUsd('gpt-5.6-terra', 1_000, 100, 800)
        );
    }

    public function testReturnsNullForUnknownModelOrMissingUsage(): void
    {
        $estimator = new CostEstimator();

        self::assertNull($estimator->estimateUsd('unknown-model', 1_000, 100));
        self::assertNull($estimator->estimateUsd('gpt-5.6-luna', null, 100));
    }
}
