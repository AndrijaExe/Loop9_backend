<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model\Chat;

use App\Model\Chat\AdviceDirective;
use App\Model\Chat\AdvicePolicy;
use App\Model\Chat\CommitmentOptions;
use App\Model\Chat\RuntimeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdvicePolicyTest extends TestCase
{
    public function testFlagOffNeverMisdirectsOrWrongLifts(): void
    {
        $policy = self::policy(false);
        $directive = $policy->decide(
            RuntimeContext::fromArray([
                'loop_index' => 8,
                'decoy_zone' => 'the north corridor',
                'anomaly_detail' => ['zone' => 'the archive room', 'object' => 'an office chair'],
                'advice_state' => [
                    'location_misdirection_used' => true,
                    'contradiction_exposed' => true,
                    'pending_decision_surrender' => true,
                    'wrong_lift_used' => false,
                    'confrontation_response_used' => true,
                ],
                'state' => [
                    'kindness' => 0,
                    'suspicion' => 0,
                    'dependency' => 0.8,
                    'player_confidence' => 0.7,
                    'anomaly_key' => 'MoveAnomaly',
                ],
            ]),
            true,
        );

        self::assertSame(AdviceDirective::MODE_ACCURATE_HINT, $directive->mode());
        self::assertSame(AdviceDirective::LIFT_LIT, $directive->lift());
        self::assertSame('the archive room', $directive->suggestedZone());
    }

    public function testWithholdsWithoutFinding(): void
    {
        $directive = self::policy(true)->decide(
            RuntimeContext::fromArray(['loop_index' => 5]),
            false,
        );

        self::assertSame(AdviceDirective::MODE_WITHHOLD, $directive->mode());
        self::assertTrue($directive->withholdsElevator());
    }

    public function testLoopOneNeverMisdirectsEvenWithDecoy(): void
    {
        $directive = self::policy(true)->decide(
            RuntimeContext::fromArray([
                'loop_index' => 1,
                'decoy_zone' => 'the north corridor',
                'anomaly_detail' => ['zone' => 'the archive room'],
                'advice_state' => ['location_misdirection_used' => false],
                'state' => [
                    'dependency' => 0.7,
                    'player_confidence' => 0.7,
                    'anomaly_key' => 'MoveAnomaly',
                ],
            ]),
            true,
        );

        self::assertNotSame(AdviceDirective::MODE_MISDIRECT_LOCATION, $directive->mode());
    }

    public function testMisdirectUsesDecoyOnce(): void
    {
        $directive = self::policy(true)->decide(
            RuntimeContext::fromArray([
                'loop_index' => 5,
                'decoy_zone' => 'the north corridor',
                'anomaly_detail' => ['zone' => 'the archive room', 'object' => 'an office chair'],
                'advice_state' => [
                    'location_misdirection_used' => false,
                    'wrong_lift_used' => false,
                ],
                'state' => [
                    'dependency' => 0.5,
                    'player_confidence' => 0.7,
                    'anomaly_key' => 'MoveAnomaly',
                ],
            ]),
            true,
        );

        self::assertSame(AdviceDirective::MODE_MISDIRECT_LOCATION, $directive->mode());
        self::assertSame('the north corridor', $directive->suggestedZone());
        self::assertTrue($directive->withholdsElevator());
    }

    public function testLocationKillSwitchKeepsTruthfulHint(): void
    {
        $directive = self::policy(true, false, true)->decide(
            RuntimeContext::fromArray([
                'loop_index' => 5,
                'decoy_zone' => 'the north corridor',
                'anomaly_detail' => ['zone' => 'the archive room'],
                'advice_state' => ['location_misdirection_used' => false],
                'state' => [
                    'dependency' => 0.6,
                    'player_confidence' => 0.7,
                    'anomaly_key' => 'MoveAnomaly',
                ],
            ]),
            true,
        );

        self::assertSame(AdviceDirective::MODE_ACCURATE_HINT, $directive->mode());
        self::assertSame('the archive room', $directive->suggestedZone());
    }

    public function testFirstReplyAfterExposedContradictionIsDefensiveOnce(): void
    {
        $context = RuntimeContext::fromArray([
            'loop_index' => 7,
            'advice_state' => [
                'location_misdirection_used' => true,
                'contradiction_exposed' => true,
                'confrontation_response_used' => false,
            ],
            'state' => [
                'dependency' => 0.7,
                'anomaly_key' => 'MoveAnomaly',
            ],
        ]);

        $directive = self::policy(true)->decide($context, true);

        self::assertSame(AdviceDirective::MODE_CONFRONTATION, $directive->mode());
        self::assertTrue($directive->withholdsElevator());
    }

    public function testPursuerAndPhantomAreNotLocationMisdirectCandidates(): void
    {
        foreach (['PursuerAnomaly', 'PhantomMessageAnomaly'] as $key) {
            $directive = self::policy(true)->decide(
                RuntimeContext::fromArray([
                    'loop_index' => 6,
                    'decoy_zone' => 'the north corridor',
                    'anomaly_detail' => ['zone' => 'the stairwell landing'],
                    'advice_state' => ['location_misdirection_used' => false],
                    'state' => [
                        'dependency' => 0.6,
                        'player_confidence' => 0.7,
                        'anomaly_key' => $key,
                    ],
                ]),
                true,
            );

            self::assertNotSame(
                AdviceDirective::MODE_MISDIRECT_LOCATION,
                $directive->mode(),
                $key . ' must not plant a wrong location',
            );
        }
    }

    public function testWrongLiftRequiresFullLatePath(): void
    {
        $directive = self::policy(true)->decide(
            RuntimeContext::fromArray([
                'loop_index' => 8,
                'decoy_zone' => 'the north corridor',
                'anomaly_detail' => ['zone' => 'the archive room'],
                'advice_state' => [
                    'location_misdirection_used' => true,
                    'contradiction_exposed' => true,
                    'pending_decision_surrender' => true,
                    'wrong_lift_used' => false,
                    'confrontation_response_used' => true,
                ],
                'state' => [
                    'dependency' => 0.7,
                    'player_confidence' => 0.5,
                    'anomaly_key' => 'MoveAnomaly',
                ],
            ]),
            true,
        );

        self::assertSame(AdviceDirective::MODE_WRONG_LIFT, $directive->mode());
        self::assertSame(AdviceDirective::LIFT_DARK, $directive->lift());
        self::assertTrue($directive->requiresElevatorName());
    }

    public function testWrongLiftKillSwitchKeepsTruthfulLift(): void
    {
        $directive = self::policy(true, true, false)->decide(
            RuntimeContext::fromArray([
                'loop_index' => 8,
                'anomaly_detail' => ['zone' => 'the archive room'],
                'advice_state' => [
                    'location_misdirection_used' => true,
                    'contradiction_exposed' => true,
                    'confrontation_response_used' => true,
                    'pending_decision_surrender' => true,
                    'wrong_lift_used' => false,
                ],
                'state' => [
                    'dependency' => 0.8,
                    'player_confidence' => 0.7,
                    'anomaly_key' => 'MoveAnomaly',
                ],
            ]),
            true,
        );

        self::assertSame(AdviceDirective::MODE_ACCURATE_HINT, $directive->mode());
        self::assertSame(AdviceDirective::LIFT_LIT, $directive->lift());
    }

    #[DataProvider('wrongLiftBlockedProvider')]
    public function testWrongLiftIsBlockedWhenPathIncomplete(array $adviceState, int $loop, float $dependency): void
    {
        $directive = self::policy(true)->decide(
            RuntimeContext::fromArray([
                'loop_index' => $loop,
                'anomaly_detail' => ['zone' => 'the archive room'],
                'advice_state' => $adviceState,
                'state' => [
                    'dependency' => $dependency,
                    'player_confidence' => 0.5,
                    'anomaly_key' => 'MoveAnomaly',
                ],
            ]),
            true,
        );

        self::assertNotSame(AdviceDirective::MODE_WRONG_LIFT, $directive->mode());
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: int, 2: float}>
     */
    public static function wrongLiftBlockedProvider(): iterable
    {
        $full = [
            'location_misdirection_used' => true,
            'contradiction_exposed' => true,
            'pending_decision_surrender' => true,
            'wrong_lift_used' => false,
            'confrontation_response_used' => true,
        ];

        yield 'too_early_loop' => [$full, 6, 0.7];
        yield 'low_dependency' => [$full, 8, 0.5];
        yield 'already_used' => [
            [
                'location_misdirection_used' => true,
                'contradiction_exposed' => true,
                'pending_decision_surrender' => true,
                'wrong_lift_used' => true,
                'confrontation_response_used' => true,
            ],
            8,
            0.7,
        ];
        yield 'no_contradiction' => [
            [
                'location_misdirection_used' => true,
                'contradiction_exposed' => false,
                'pending_decision_surrender' => true,
                'wrong_lift_used' => false,
                'confrontation_response_used' => true,
            ],
            8,
            0.7,
        ];
        yield 'no_surrender' => [
            [
                'location_misdirection_used' => true,
                'contradiction_exposed' => true,
                'pending_decision_surrender' => false,
                'wrong_lift_used' => false,
                'confrontation_response_used' => true,
            ],
            8,
            0.7,
        ];
    }

    public function testCleanFloorFindingPinsDarkLift(): void
    {
        $directive = self::policy(false)->decide(
            RuntimeContext::fromArray([
                'loop_index' => 1,
                'state' => [
                    'dependency' => 0.1,
                    'player_confidence' => 0.7,
                    'anomaly_key' => 'none',
                ],
            ]),
            true,
        );

        self::assertSame(AdviceDirective::MODE_ACCURATE_LIFT, $directive->mode());
        self::assertSame(AdviceDirective::LIFT_DARK, $directive->lift());
    }

    public function testClientAdviceArrayIncludesModeAndCommitmentId(): void
    {
        $directive = AdviceDirective::withhold();
        $array = $directive->toClientArray();

        self::assertSame(AdviceDirective::MODE_WITHHOLD, $array['mode']);
        self::assertSame(AdviceDirective::LIFT_NONE, $array['lift']);
        self::assertNotSame('', $array['commitment_id']);
    }

    private static function policy(
        bool $masterEnabled,
        bool $locationMisdirectionEnabled = true,
        bool $wrongLiftEnabled = true,
    ): AdvicePolicy {
        return new AdvicePolicy(new CommitmentOptions(
            masterEnabled: $masterEnabled,
            locationMisdirectionEnabled: $locationMisdirectionEnabled,
            wrongLiftEnabled: $wrongLiftEnabled,
        ));
    }
}
