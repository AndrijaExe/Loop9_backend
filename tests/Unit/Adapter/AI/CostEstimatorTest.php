<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\AI;

use App\Adapter\AI\CostEstimator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CostEstimatorTest extends TestCase
{
    #[DataProvider('modelCosts')]
    public function testEstimatesCurrentModelCosts(string $model, float $expectedUsd): void
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
        yield 'GPT 5.6 Terra' => ['gpt-5.6-terra', 0.004];
        yield 'GPT 5.6 Luna' => ['gpt-5.6-luna', 0.0016];
        yield 'GPT 5.6 Sol' => ['gpt-5.6-sol', 0.008];
        yield 'Groq GPT OSS 120B' => ['openai/gpt-oss-120b', 0.00021];
    }

    public function testReturnsNullForUnknownModelOrMissingUsage(): void
    {
        $estimator = new CostEstimator();

        self::assertNull($estimator->estimateUsd('unknown-model', 1_000, 100));
        self::assertNull($estimator->estimateUsd('gpt-5.6-luna', null, 100));
    }
}
