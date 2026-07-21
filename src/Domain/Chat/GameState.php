<?php

declare(strict_types=1);

namespace App\Domain\Chat;

/**
 * Relationship / loop state sent by the Unreal client.
 *
 * Contract (from Loop9BackendChatService):
 * - kindness: discrete -1|0|1
 * - suspicion: discrete -1|0|1
 * - dependency: 0.0–1.0
 * - player_confidence: 0.0–1.0 (trust)
 * - repeat_anomaly: bool
 * - anomaly_key: string
 */
final class GameState
{
    public const MAX_ANOMALY_KEY_LENGTH = 128;

    public function __construct(
        private readonly ?int $kindness = null,
        private readonly ?int $suspicion = null,
        private readonly ?float $dependency = null,
        private readonly ?float $playerConfidence = null,
        private readonly ?bool $repeatAnomaly = null,
        private readonly ?string $anomalyKey = null,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $anomalyKey = null;
        if (isset($raw['anomaly_key']) && is_string($raw['anomaly_key'])) {
            $trimmed = trim($raw['anomaly_key']);
            if ($trimmed !== '') {
                $anomalyKey = mb_substr($trimmed, 0, self::MAX_ANOMALY_KEY_LENGTH);
            }
        }

        return new self(
            kindness: self::discreteDelta($raw['kindness'] ?? null),
            suspicion: self::discreteDelta($raw['suspicion'] ?? null),
            dependency: self::unitFloat($raw['dependency'] ?? null),
            playerConfidence: self::unitFloat($raw['player_confidence'] ?? null),
            repeatAnomaly: is_bool($raw['repeat_anomaly'] ?? null) ? $raw['repeat_anomaly'] : null,
            anomalyKey: $anomalyKey,
        );
    }

    public function kindness(): ?int
    {
        return $this->kindness;
    }

    public function suspicion(): ?int
    {
        return $this->suspicion;
    }

    public function dependency(): ?float
    {
        return $this->dependency;
    }

    public function playerConfidence(): ?float
    {
        return $this->playerConfidence;
    }

    public function repeatAnomaly(): ?bool
    {
        return $this->repeatAnomaly;
    }

    public function anomalyKey(): ?string
    {
        return $this->anomalyKey;
    }

    /**
     * Low kindness is treated as disrespectful attitude toward the AI.
     */
    public function isDisrespectful(): bool
    {
        return $this->kindness !== null && $this->kindness < 0;
    }

    /**
     * High suspicion / low confidence maps to elevated nervousness.
     */
    public function isHighNervousness(): bool
    {
        if ($this->suspicion !== null && $this->suspicion > 0) {
            return true;
        }

        return $this->playerConfidence !== null && $this->playerConfidence < 0.35;
    }

    public function isHighDependency(): bool
    {
        return $this->dependency !== null && $this->dependency >= 0.62;
    }

    public function isModeratelyDependent(): bool
    {
        // Dependency begins shaping the Obedient path around this range.
        return $this->dependency !== null && $this->dependency >= 0.45;
    }

    /**
     * @return array<string, int|float|bool|string>
     */
    public function toPromptArray(): array
    {
        $out = [];

        if ($this->kindness !== null) {
            $out['kindness'] = $this->kindness;
        }
        if ($this->suspicion !== null) {
            $out['suspicion'] = $this->suspicion;
        }
        if ($this->dependency !== null) {
            $out['dependency'] = $this->dependency;
        }
        if ($this->playerConfidence !== null) {
            $out['player_confidence'] = $this->playerConfidence;
        }
        if ($this->repeatAnomaly !== null) {
            $out['repeat_anomaly'] = $this->repeatAnomaly;
        }
        if ($this->anomalyKey !== null && $this->anomalyKey !== '') {
            $out['anomaly_key'] = $this->anomalyKey;
        }

        return $out;
    }

    private static function discreteDelta(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return in_array($int, [-1, 0, 1], true) ? $int : null;
    }

    private static function unitFloat(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }
}
