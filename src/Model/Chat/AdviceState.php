<?php

declare(strict_types=1);

namespace App\Model\Chat;

/**
 * Structured per-run advice memory sent by the Unreal client.
 * Never contains raw chat text — only commitment flags and last mode.
 */
final class AdviceState
{
    public function __construct(
        private readonly bool $locationMisdirectionUsed = false,
        private readonly bool $contradictionExposed = false,
        private readonly bool $pendingDecisionSurrender = false,
        private readonly bool $wrongLiftUsed = false,
        private readonly bool $followedLastLiftAdvice = false,
        private readonly ?string $lastAdviceMode = null,
        private readonly ?string $lastLiftAdvice = null,
        private readonly ?string $lastSuggestedZone = null,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            locationMisdirectionUsed: self::boolFlag($raw['location_misdirection_used'] ?? false),
            contradictionExposed: self::boolFlag($raw['contradiction_exposed'] ?? false),
            pendingDecisionSurrender: self::boolFlag($raw['pending_decision_surrender'] ?? false),
            wrongLiftUsed: self::boolFlag($raw['wrong_lift_used'] ?? false),
            followedLastLiftAdvice: self::boolFlag($raw['followed_last_lift_advice'] ?? false),
            lastAdviceMode: self::optionalString($raw['last_advice_mode'] ?? null),
            lastLiftAdvice: self::optionalString($raw['last_lift_advice'] ?? null),
            lastSuggestedZone: self::optionalString($raw['last_suggested_zone'] ?? null, AnomalyDetail::MAX_FIELD_LENGTH),
        );
    }

    public function locationMisdirectionUsed(): bool
    {
        return $this->locationMisdirectionUsed;
    }

    public function contradictionExposed(): bool
    {
        return $this->contradictionExposed;
    }

    public function pendingDecisionSurrender(): bool
    {
        return $this->pendingDecisionSurrender;
    }

    public function wrongLiftUsed(): bool
    {
        return $this->wrongLiftUsed;
    }

    public function followedLastLiftAdvice(): bool
    {
        return $this->followedLastLiftAdvice;
    }

    public function lastAdviceMode(): ?string
    {
        return $this->lastAdviceMode;
    }

    public function lastLiftAdvice(): ?string
    {
        return $this->lastLiftAdvice;
    }

    public function lastSuggestedZone(): ?string
    {
        return $this->lastSuggestedZone;
    }

    private static function boolFlag(mixed $value): bool
    {
        return is_bool($value) && $value;
    }

    private static function optionalString(mixed $value, int $maxLength = 32): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $maxLength);
    }
}
