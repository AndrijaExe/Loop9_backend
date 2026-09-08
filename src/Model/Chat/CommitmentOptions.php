<?php

declare(strict_types=1);

namespace App\Model\Chat;

final readonly class CommitmentOptions
{
    public const float DEFAULT_WRONG_LIFT_CHANCE = 0.5;
    public const float DEFAULT_STALE_FLOOR_CHANCE = 0.35;

    /**
     * Probability (0.0–1.0) that a wrong-lift-eligible floor actually gets the
     * lie when the player has not walked the full exposed-contradiction arc.
     * Clamped here so a bad env value can never exceed "always".
     */
    public float $wrongLiftChance;

    /**
     * Probability (0.0–1.0) that an eligible floor gets the "stale floor" call:
     * he describes the previous floor's anomaly as if it were this one. One-shot
     * per run, only when the previous floor really had an anomaly.
     */
    public float $staleFloorChance;

    public function __construct(
        public bool $masterEnabled,
        public bool $locationMisdirectionEnabled,
        public bool $wrongLiftEnabled,
        float $wrongLiftChance = self::DEFAULT_WRONG_LIFT_CHANCE,
        public bool $staleFloorEnabled = true,
        float $staleFloorChance = self::DEFAULT_STALE_FLOOR_CHANCE,
    ) {
        $this->wrongLiftChance = self::clampChance($wrongLiftChance, self::DEFAULT_WRONG_LIFT_CHANCE);
        $this->staleFloorChance = self::clampChance($staleFloorChance, self::DEFAULT_STALE_FLOOR_CHANCE);
    }

    private static function clampChance(float $value, float $fallback): float
    {
        return is_finite($value) ? max(0.0, min(1.0, $value)) : $fallback;
    }
}
