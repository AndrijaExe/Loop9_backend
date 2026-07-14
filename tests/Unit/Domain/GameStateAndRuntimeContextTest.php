<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Chat\GameState;
use App\Domain\Chat\RuntimeContext;
use PHPUnit\Framework\TestCase;

final class GameStateAndRuntimeContextTest extends TestCase
{
    public function testParsesUnrealStateContract(): void
    {
        $state = GameState::fromArray([
            'kindness' => -1,
            'suspicion' => 1,
            'dependency' => 0.9,
            'player_confidence' => 0.2,
            'repeat_anomaly' => true,
            'anomaly_key' => 'DoorLock',
        ]);

        self::assertTrue($state->isDisrespectful());
        self::assertTrue($state->isHighDependency());
        self::assertTrue($state->isHighNervousness());
        self::assertSame('DoorLock', $state->anomalyKey());
    }

    public function testRuntimeContextDefaultsLoopIndex(): void
    {
        $context = RuntimeContext::fromArray([
            'language' => 'sr',
            'ai_stability' => 0.4,
            'state' => [
                'kindness' => 1,
                'suspicion' => 0,
                'dependency' => 0.2,
                'player_confidence' => 0.8,
            ],
        ]);

        self::assertSame(1, $context->loopIndex());
        self::assertSame('sr', $context->language());
        self::assertNotNull($context->state());
        self::assertFalse($context->state()->isDisrespectful());
    }

    public function testClampsLoopIndexToValidRange(): void
    {
        self::assertSame(1, RuntimeContext::fromArray(['loop_index' => 0])->loopIndex());
        self::assertSame(1, RuntimeContext::fromArray(['loop_index' => -5])->loopIndex());
        self::assertSame(9, RuntimeContext::fromArray(['loop_index' => 99])->loopIndex());
        self::assertSame(7, RuntimeContext::fromArray(['loop_index' => 7])->loopIndex());
    }

    public function testTruncatesLongAnomalyKey(): void
    {
        $state = GameState::fromArray([
            'anomaly_key' => str_repeat('k', GameState::MAX_ANOMALY_KEY_LENGTH + 40),
        ]);

        self::assertNotNull($state->anomalyKey());
        self::assertSame(GameState::MAX_ANOMALY_KEY_LENGTH, mb_strlen((string) $state->anomalyKey()));
    }
}
