<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\AI;

use App\Model\Chat\RuntimeContext;
use App\Adapter\AI\PromptFactory;
use PHPUnit\Framework\TestCase;

final class PromptFactoryTest extends TestCase
{
    private PromptFactory $factory;

    protected function setUp(): void
    {
        $projectDir = dirname(__DIR__, 4);
        $this->factory = new PromptFactory($projectDir . '/config/prompts', '');
    }

    public function testPromptsRequireEvidenceBasedGuidanceAndExplicitStateRubric(): void
    {
        foreach ([1, 4] as $loopIndex) {
            $prompt = $this->factory->buildSystemPrompt($loopIndex);

            self::assertStringContainsString('exactly nine', $prompt);
            self::assertStringContainsString('distrust', $prompt);
            self::assertStringContainsString('asking what a sound/object is', $prompt);
            self::assertStringContainsString('surrenders the decision', $prompt);
            self::assertStringNotContainsString('confidently wrong', $prompt);
        }
    }

    public function testBothPromptsCarryAtmosphericVoiceGuidance(): void
    {
        foreach ([1, 4] as $loopIndex) {
            $prompt = $this->factory->buildSystemPrompt($loopIndex);

            self::assertStringContainsString('Atmosphere comes from concrete detail', $prompt);
            self::assertStringContainsString('same shape every time', $prompt);
            self::assertStringContainsString('You are on a phone in the same building', $prompt);
        }
    }

    public function testPromptsEstablishCharacterBeforeGameplayRules(): void
    {
        foreach ([1, 4] as $loopIndex) {
            $prompt = $this->factory->buildSystemPrompt($loopIndex);

            self::assertLessThan(
                strpos($prompt, 'lit elevator'),
                strpos($prompt, 'You are Dragojlo'),
                'Identity must precede the elevator rules so the model anchors on voice first.',
            );
        }
    }

    /**
     * A stable line is the state a player sits in for nearly a whole run, so the
     * high-stability note must still carry texture instead of flattening the voice.
     */
    public function testHighStabilityToneNoteIsNotFlat(): void
    {
        $prompt = $this->factory->buildRuntimeContextPrompt(RuntimeContext::fromArray([
            'loop_index' => 2,
            'ai_stability' => 0.9,
        ]));

        self::assertStringContainsString('Speech stability is high', $prompt);
        self::assertStringContainsString('leaning into the receiver', $prompt);
        self::assertStringNotContainsString('clear and grounded', $prompt);
    }

    public function testTunedDependencyStateProducesDirectToneWithoutMisleadingRespectfulPlayer(): void
    {
        $context = RuntimeContext::fromArray([
            'loop_index' => 5,
            'state' => [
                'kindness' => 1,
                'suspicion' => 0,
                'dependency' => 0.53,
                'player_confidence' => 0.8,
            ],
        ]);

        $prompt = $this->factory->buildRuntimeContextPrompt($context);

        self::assertStringContainsString('increasingly asks for direction', $prompt);
        self::assertStringNotContainsString('misleading guidance is allowed', $prompt);
    }

    public function testCleanFirstLoopAddsExplicitNoHallucinationGuard(): void
    {
        $prompt = $this->factory->buildRuntimeContextPrompt(RuntimeContext::fromArray([
            'loop_index' => 1,
            'anomaly_context' => 'No active anomaly currently detected.',
        ]));

        self::assertStringContainsString('verified clean baseline loop', $prompt);
        self::assertStringContainsString('dark elevator', $prompt);
    }

    public function testNormalizesInternalAnomalyLabelsInRuntimeContext(): void
    {
        $prompt = $this->factory->buildRuntimeContextPrompt(RuntimeContext::fromArray([
            'loop_index' => 5,
            'anomaly_context' => 'Active anomaly types: LightFlickerAnomaly.',
            'state' => [
                'anomaly_key' => 'HideAnomaly',
            ],
        ]));

        self::assertStringContainsString('Light (light flicker)', $prompt);
        self::assertStringContainsString('Hide (missing/hidden objects)', $prompt);
        self::assertStringNotContainsString('LightFlickerAnomaly', $prompt);
        self::assertStringNotContainsString('HideAnomaly', $prompt);
    }
}
