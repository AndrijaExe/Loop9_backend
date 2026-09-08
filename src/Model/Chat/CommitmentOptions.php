<?php

declare(strict_types=1);

namespace App\Model\Chat;

final readonly class CommitmentOptions
{
    public const float DEFAULT_WRONG_LIFT_CHANCE = 0.5;

    /**
     * Probability (0.0–1.0) that a wrong-lift-eligible floor actually gets the
     * lie when the player has not walked the full exposed-contradiction arc.
     * Clamped here so a bad env value can never exceed "always".
     */
    public float $wrongLiftChance;

    public function __construct(
        public bool $masterEnabled,
        public bool $locationMisdirectionEnabled,
        public bool $wrongLiftEnabled,
        float $wrongLiftChance = self::DEFAULT_WRONG_LIFT_CHANCE,
    ) {
        $this->wrongLiftChance = is_finite($wrongLiftChance)
            ? max(0.0, min(1.0, $wrongLiftChance))
            : self::DEFAULT_WRONG_LIFT_CHANCE;
    }
}
