<?php

declare(strict_types=1);

namespace App\Model\Chat;

/**
 * Deterministic server decision about what Dragojlo is allowed to commit to.
 * PromptFactory only renders this; the model does not choose the mode.
 */
final class AdviceDirective
{
    public const MODE_WITHHOLD = 'withhold';
    public const MODE_ACCURATE_HINT = 'accurate_hint';
    public const MODE_MISDIRECT_LOCATION = 'misdirect_location';
    public const MODE_WRONG_LIFT = 'wrong_lift';
    public const MODE_ACCURATE_LIFT = 'accurate_lift';

    public const LIFT_NONE = 'none';
    public const LIFT_LIT = 'lit';
    public const LIFT_DARK = 'dark';

    public function __construct(
        private readonly string $mode,
        private readonly string $lift = self::LIFT_NONE,
        private readonly ?string $suggestedZone = null,
        private readonly ?string $suggestedObject = null,
        private readonly string $commitmentId = '',
        private readonly bool $allowMisleadingTone = false,
        private readonly bool $anomalyActive = false,
    ) {
    }

    public static function withhold(): self
    {
        return new self(mode: self::MODE_WITHHOLD, commitmentId: self::newCommitmentId());
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function lift(): string
    {
        return $this->lift;
    }

    public function suggestedZone(): ?string
    {
        return $this->suggestedZone;
    }

    public function suggestedObject(): ?string
    {
        return $this->suggestedObject;
    }

    public function commitmentId(): string
    {
        return $this->commitmentId;
    }

    public function allowMisleadingTone(): bool
    {
        return $this->allowMisleadingTone;
    }

    public function anomalyActive(): bool
    {
        return $this->anomalyActive;
    }

    public function withholdsElevator(): bool
    {
        return $this->mode === self::MODE_WITHHOLD
            || $this->mode === self::MODE_MISDIRECT_LOCATION
            || $this->lift === self::LIFT_NONE;
    }

    public function requiresElevatorName(): bool
    {
        // Only the forced late wrong-lift path must include the expected name.
        // Accurate lift guidance still teaches lit/dark in the prompt, but we do
        // not burn a paid retry when the model phrases the verdict without the
        // exact vocabulary.
        return $this->mode === self::MODE_WRONG_LIFT;
    }

    /**
     * @return array{mode: string, lift: string, suggested_zone?: string, commitment_id: string}
     */
    public function toClientArray(): array
    {
        $out = [
            'mode' => $this->mode,
            'lift' => $this->lift,
            'commitment_id' => $this->commitmentId,
        ];

        if ($this->suggestedZone !== null && $this->suggestedZone !== '') {
            $out['suggested_zone'] = $this->suggestedZone;
        }

        return $out;
    }

    private static function newCommitmentId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
