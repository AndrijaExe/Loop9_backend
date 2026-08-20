<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\AI;

use App\Model\Chat\RuntimeContext;
use App\Adapter\AI\PromptFactory;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * The in-game tutorial teaches the elevators by their localized names, so a
     * reply that switches to the English pair or invents a synonym contradicts
     * the wording the player was taught by the same character.
     */
    public function testPromptsPinTheLocalizedElevatorWording(): void
    {
        foreach ([1, 4] as $loopIndex) {
            $prompt = $this->factory->buildSystemPrompt($loopIndex);

            self::assertStringContainsString('osvetljeni lift', $prompt);
            self::assertStringContainsString('mračni lift', $prompt);
            self::assertStringContainsString('beleuchteter Aufzug', $prompt);
            self::assertStringContainsString('ascenseur éclairé', $prompt);
            self::assertStringContainsString('освещённый лифт', $prompt);
            self::assertStringContainsString('never a synonym of your own', $prompt);
        }
    }

    /**
     * The runtime context carries the internal taxonomy, so the model has to be
     * told those names are reference only and not speakable to the player.
     */
    public function testPromptsForbidSpeakingInternalAnomalyLabels(): void
    {
        foreach ([1, 4] as $loopIndex) {
            $prompt = $this->factory->buildSystemPrompt($loopIndex);

            self::assertStringContainsString('PhantomMessage', $prompt);
            self::assertStringContainsString('never be said to the player', $prompt);
        }
    }

    public function testPromptsNeverDiscourageSearchingTheFloor(): void
    {
        foreach ([1, 4] as $loopIndex) {
            $prompt = $this->factory->buildSystemPrompt($loopIndex);

            self::assertStringContainsString('searching the floor is the whole of their job', $prompt);
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

    /**
     * No conversation history reaches the model, so the "once every five replies"
     * pacing has to be decided per request instead of trusted to the model.
     */
    public function testWarmAddressIsPacedWithoutConversationMemory(): void
    {
        $context = RuntimeContext::fromArray(['loop_index' => 4]);
        $allowedPhrase = 'You may use a warm form of address once in this reply.';

        $allowed = 0;
        for ($i = 0; $i < 100; ++$i) {
            if (str_contains($this->factory->buildRuntimeContextPrompt($context, 'message ' . $i), $allowedPhrase)) {
                ++$allowed;
            }
        }

        self::assertGreaterThan(0, $allowed, 'The warm address must remain reachable.');
        self::assertLessThan(40, $allowed, 'The warm address must stay a minority of replies.');
    }

    public function testSameMessageAlwaysYieldsTheSameAddressDirective(): void
    {
        $context = RuntimeContext::fromArray(['loop_index' => 4]);

        self::assertSame(
            $this->factory->buildRuntimeContextPrompt($context, 'Svetlo mi trepti.'),
            $this->factory->buildRuntimeContextPrompt($context, 'Svetlo mi trepti.'),
        );
    }

    /**
     * A live probe had a model name the moved object and still send the player
     * to the dark elevator, so the required recommendation is stated outright.
     */
    public function testActiveAnomalyPinsTheLitElevator(): void
    {
        $prompt = $this->factory->buildRuntimeContextPrompt(RuntimeContext::fromArray([
            'loop_index' => 5,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'state' => ['kindness' => 0, 'dependency' => 0.66, 'anomaly_key' => 'MoveAnomaly'],
        ]));

        self::assertStringContainsString('only correct recommendation is the lit elevator', $prompt);
    }

    public function testCleanLoopNeverPinsTheLitElevator(): void
    {
        $prompt = $this->factory->buildRuntimeContextPrompt(RuntimeContext::fromArray([
            'loop_index' => 6,
            'anomaly_context' => 'No active anomaly currently detected.',
            'state' => ['kindness' => 0, 'dependency' => 0.3],
        ]));

        self::assertStringNotContainsString('only correct recommendation is the lit elevator', $prompt);
    }

    /**
     * A player who has earned misleading guidance must not be handed a rule
     * that overrides it.
     */
    public function testAnomalyPinYieldsToPermittedMisleadingGuidance(): void
    {
        $prompt = $this->factory->buildRuntimeContextPrompt(RuntimeContext::fromArray([
            'loop_index' => 6,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'state' => ['kindness' => -1, 'dependency' => 0.7, 'anomaly_key' => 'MoveAnomaly'],
        ]));

        self::assertStringContainsString('misleading guidance is allowed', $prompt);
        self::assertStringNotContainsString('only correct recommendation is the lit elevator', $prompt);
    }

    /**
     * @return array<string, array{0: float, 1: list<string>, 2: list<string>}>
     */
    public static function trustLadderProvider(): array
    {
        return [
            'distrust reveals nothing' => [
                0.2,
                ['cannot tell where the anomaly is', 'Do not name a place or an object'],
                ['east corridor', 'office chair'],
            ],
            'partial trust reveals the place only' => [
                0.45,
                [
                    'roughly where the anomaly is, but not which object',
                    'never suggest skipping or avoiding that place',
                    'east corridor',
                ],
                ['office chair'],
            ],
            'earned trust reveals place and kind' => [
                0.8,
                [
                    'roughly where the anomaly is and what kind of thing it is',
                    'never suggest skipping or avoiding that place',
                    'east corridor | office chair',
                ],
                [],
            ],
        ];
    }

    #[DataProvider('trustLadderProvider')]
    public function testTrustDecidesHowMuchOfTheAnomalyHeCanTell(
        float $trust,
        array $expected,
        array $forbidden,
    ): void {
        $prompt = $this->factory->buildRuntimeContextPrompt(RuntimeContext::fromArray([
            'loop_index' => 5,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'anomaly_detail' => ['zone' => 'east corridor', 'object' => 'office chair'],
            'state' => ['player_confidence' => $trust, 'anomaly_key' => 'MoveAnomaly'],
        ]));

        foreach ($expected as $phrase) {
            self::assertStringContainsString($phrase, $prompt);
        }

        foreach ($forbidden as $phrase) {
            self::assertStringNotContainsString($phrase, $prompt);
        }
    }

    /**
     * A phantom chat message has no place on the floor, so only the kind can be
     * offered and he must not invent somewhere for it to be.
     */
    public function testAnomalyWithoutAPlaceOffersOnlyTheKind(): void
    {
        $prompt = $this->factory->buildRuntimeContextPrompt(RuntimeContext::fromArray([
            'loop_index' => 8,
            'anomaly_detail' => ['object' => 'a message in this chat'],
            'state' => ['player_confidence' => 0.9, 'anomaly_key' => 'PhantomMessageAnomaly'],
        ]));

        self::assertStringContainsString('what kind of thing is wrong, but not where it is', $prompt);
        self::assertStringContainsString('never a place', $prompt);
    }

    public function testAbsentAnomalyDetailLeavesTheRuntimePromptUnchanged(): void
    {
        $prompt = $this->factory->buildRuntimeContextPrompt(RuntimeContext::fromArray([
            'loop_index' => 5,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'state' => ['player_confidence' => 0.9, 'anomaly_key' => 'MoveAnomaly'],
        ]));

        self::assertStringNotContainsString('You can tell', $prompt);
        self::assertStringNotContainsString('cannot tell where the anomaly is', $prompt);
    }

    /**
     * A clean floor arrives with anomaly_key set to "none", and the dark
     * elevator is the right call there, so the lit-elevator directive must stay
     * out of the prompt.
     */
    public function testCleanFloorIsNotPinnedToTheLitElevator(): void
    {
        $prompt = $this->factory->buildRuntimeContextPrompt(RuntimeContext::fromArray([
            'loop_index' => 5,
            'anomaly_context' => 'No active anomaly currently detected.',
            'state' => ['player_confidence' => 0.7, 'dependency' => 0.2, 'anomaly_key' => 'none'],
        ]));

        self::assertStringNotContainsString('An anomaly is confirmed active', $prompt);
        self::assertStringNotContainsString('anomaly_key', $prompt);
    }

    public function testPromptsForbidInventingAPlaceOrObject(): void
    {
        foreach ([1, 4] as $loopIndex) {
            $prompt = $this->factory->buildSystemPrompt($loopIndex);

            self::assertStringContainsString('Never invent a place or an object', $prompt);
            self::assertStringContainsString('that boundary is absolute', $prompt);
        }
    }

    public function testPromptsStateTheSentenceBudgetTheValidatorEnforces(): void
    {
        foreach ([1, 4] as $loopIndex) {
            $prompt = $this->factory->buildSystemPrompt($loopIndex);

            self::assertStringContainsString('at most TWO of the marks', $prompt);
            self::assertStringContainsString('run of dots counts as one', $prompt);
        }
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
