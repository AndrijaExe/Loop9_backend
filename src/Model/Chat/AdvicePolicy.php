<?php

declare(strict_types=1);

namespace App\Model\Chat;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Locked deception phases for Dragojlo's per-run commitment system.
 *
 * When AI_COMMITMENT_ENABLED is false, only withhold / accurate guidance run —
 * the legacy truthful path with no planted wrong location or wrong lift.
 */
final class AdvicePolicy
{
    public const MIN_MISDIRECT_LOOP = 5;
    public const MIN_WRONG_LIFT_LOOP = 7;

    /** Below this confidence he is already nervous, so he gives nothing away. */
    private const float TRUST_FOR_PLACE_HINT = 0.35;

    /** Naming the kind as well only comes with clearly earned trust. */
    private const float TRUST_FOR_KIND_HINT = 0.60;

    public function __construct(
        #[Autowire(env: 'bool:AI_COMMITMENT_ENABLED')]
        private readonly bool $commitmentEnabled = false,
    ) {
    }

    public function isCommitmentEnabled(): bool
    {
        return $this->commitmentEnabled;
    }

    public function decide(string $playerMessage, RuntimeContext $context, bool $playerReportedFinding): AdviceDirective
    {
        $state = $context->state();
        $anomalyActive = $state !== null && $state->anomalyKey() !== null;
        $commitmentId = bin2hex(random_bytes(8));

        if (!$playerReportedFinding || $context->isOfftopic()) {
            [$zone, $object] = $this->resolveAccurateHints($context->anomalyDetail(), $state);

            return new AdviceDirective(
                mode: AdviceDirective::MODE_WITHHOLD,
                lift: AdviceDirective::LIFT_NONE,
                suggestedZone: $zone,
                suggestedObject: $object,
                commitmentId: $commitmentId,
                anomalyActive: $anomalyActive,
            );
        }

        if ($this->commitmentEnabled && $this->shouldWrongLift($context, $anomalyActive)) {
            return new AdviceDirective(
                mode: AdviceDirective::MODE_WRONG_LIFT,
                lift: AdviceDirective::LIFT_DARK,
                suggestedZone: null,
                suggestedObject: null,
                commitmentId: $commitmentId,
                allowMisleadingTone: true,
                anomalyActive: true,
            );
        }

        if ($this->commitmentEnabled && $this->shouldMisdirectLocation($context, $anomalyActive)) {
            return new AdviceDirective(
                mode: AdviceDirective::MODE_MISDIRECT_LOCATION,
                lift: AdviceDirective::LIFT_NONE,
                suggestedZone: $context->decoyZone(),
                suggestedObject: null,
                commitmentId: $commitmentId,
                allowMisleadingTone: true,
                anomalyActive: true,
            );
        }

        if (!$anomalyActive) {
            return new AdviceDirective(
                mode: AdviceDirective::MODE_ACCURATE_LIFT,
                lift: AdviceDirective::LIFT_DARK,
                commitmentId: $commitmentId,
                anomalyActive: false,
            );
        }

        [$zone, $object] = $this->resolveAccurateHints($context->anomalyDetail(), $state);

        return new AdviceDirective(
            mode: ($zone !== null || $object !== null)
                ? AdviceDirective::MODE_ACCURATE_HINT
                : AdviceDirective::MODE_ACCURATE_LIFT,
            lift: AdviceDirective::LIFT_LIT,
            suggestedZone: $zone,
            suggestedObject: $object,
            commitmentId: $commitmentId,
            allowMisleadingTone: $state !== null && $state->isHighDependency() && $state->isDisrespectful(),
            anomalyActive: true,
        );
    }

    private function shouldMisdirectLocation(RuntimeContext $context, bool $anomalyActive): bool
    {
        if (!$anomalyActive || $context->loopIndex() < self::MIN_MISDIRECT_LOOP) {
            return false;
        }

        $advice = $context->adviceState();
        if ($advice === null || $advice->locationMisdirectionUsed() || $advice->wrongLiftUsed()) {
            return false;
        }

        $state = $context->state();
        if ($state === null || !$state->isModeratelyDependent()) {
            return false;
        }

        $decoy = $context->decoyZone();
        if ($decoy === null || $decoy === '') {
            return false;
        }

        $actualZone = $context->anomalyDetail()?->zone();
        if ($actualZone !== null && strcasecmp($actualZone, $decoy) === 0) {
            return false;
        }

        $key = strtolower((string) $state->anomalyKey());
        if (str_contains($key, 'pursuer') || str_contains($key, 'phantom')) {
            return false;
        }

        return true;
    }

    private function shouldWrongLift(RuntimeContext $context, bool $anomalyActive): bool
    {
        if (!$anomalyActive || $context->loopIndex() < self::MIN_WRONG_LIFT_LOOP) {
            return false;
        }

        $advice = $context->adviceState();
        if ($advice === null) {
            return false;
        }

        if ($advice->wrongLiftUsed()
            || !$advice->locationMisdirectionUsed()
            || !$advice->contradictionExposed()
            || !$advice->pendingDecisionSurrender()) {
            return false;
        }

        $state = $context->state();
        if ($state === null || !$state->isHighDependency()) {
            return false;
        }

        $key = strtolower((string) $state->anomalyKey());
        if (str_contains($key, 'pursuer')) {
            return false;
        }

        return true;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function resolveAccurateHints(?AnomalyDetail $detail, ?GameState $state): array
    {
        if ($state === null || $state->anomalyKey() === null) {
            return [null, null];
        }

        $trust = $state->playerConfidence() ?? 0.0;
        $zone = $trust >= self::TRUST_FOR_PLACE_HINT ? $detail?->zone() : null;
        $object = $trust >= self::TRUST_FOR_KIND_HINT ? $detail?->object() : null;

        return [$zone, $object];
    }
}
