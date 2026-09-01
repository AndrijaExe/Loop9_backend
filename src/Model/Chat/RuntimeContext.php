<?php

declare(strict_types=1);

namespace App\Model\Chat;

final class RuntimeContext
{
    public const MIN_LOOP_INDEX = 1;
    public const MAX_LOOP_INDEX = 9;

    public function __construct(
        private readonly ?string $language = null,
        private readonly ?float $aiStability = null,
        private readonly ?GameState $state = null,
        private readonly ?string $anomalyContext = null,
        private readonly int $loopIndex = self::MIN_LOOP_INDEX,
        private readonly bool $offtopic = false,
        private readonly ?AnomalyDetail $anomalyDetail = null,
        private readonly ?string $decoyZone = null,
        private readonly ?AdviceState $adviceState = null,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $loopIndex = self::MIN_LOOP_INDEX;
        if (isset($raw['loop_index']) && is_numeric($raw['loop_index'])) {
            $loopIndex = max(self::MIN_LOOP_INDEX, min(self::MAX_LOOP_INDEX, (int) $raw['loop_index']));
        }

        $stability = null;
        if (isset($raw['ai_stability']) && is_numeric($raw['ai_stability'])) {
            $stability = max(0.0, min(1.0, (float) $raw['ai_stability']));
        }

        $language = null;
        if (isset($raw['language']) && is_string($raw['language'])) {
            $trimmed = trim($raw['language']);
            $language = $trimmed !== '' ? $trimmed : null;
        }

        $anomalyContext = null;
        if (isset($raw['anomaly_context']) && is_string($raw['anomaly_context'])) {
            $trimmed = trim($raw['anomaly_context']);
            $anomalyContext = $trimmed !== '' ? $trimmed : null;
        }

        $state = null;
        if (isset($raw['state']) && is_array($raw['state']) && $raw['state'] !== []) {
            $state = GameState::fromArray($raw['state']);
        }

        $offtopic = isset($raw['offtopic']) && is_bool($raw['offtopic']) && $raw['offtopic'];

        $anomalyDetail = null;
        if (isset($raw['anomaly_detail']) && is_array($raw['anomaly_detail'])) {
            $anomalyDetail = AnomalyDetail::fromArray($raw['anomaly_detail']);
        }

        $decoyZone = null;
        if (isset($raw['decoy_zone'])) {
            $decoyZone = AnomalyDetail::fromArray(['zone' => $raw['decoy_zone']])?->zone();
        }

        $adviceState = null;
        if (isset($raw['advice_state']) && is_array($raw['advice_state'])) {
            $adviceState = AdviceState::fromArray($raw['advice_state']);
        }

        return new self(
            language: $language,
            aiStability: $stability,
            state: $state,
            anomalyContext: $anomalyContext,
            loopIndex: $loopIndex,
            offtopic: $offtopic,
            anomalyDetail: $anomalyDetail,
            decoyZone: $decoyZone,
            adviceState: $adviceState,
        );
    }

    public function language(): ?string
    {
        return $this->language;
    }

    public function aiStability(): ?float
    {
        return $this->aiStability;
    }

    public function state(): ?GameState
    {
        return $this->state;
    }

    public function anomalyContext(): ?string
    {
        return $this->anomalyContext;
    }

    public function anomalyDetail(): ?AnomalyDetail
    {
        return $this->anomalyDetail;
    }

    public function decoyZone(): ?string
    {
        return $this->decoyZone;
    }

    public function adviceState(): ?AdviceState
    {
        return $this->adviceState;
    }

    public function loopIndex(): int
    {
        return $this->loopIndex;
    }

    public function isOfftopic(): bool
    {
        return $this->offtopic;
    }
}
