<?php

declare(strict_types=1);

namespace App\Model\Chat;

/**
 * What Dragojlo remembers about this player from earlier runs, as sent by the
 * Unreal client (`run_history`). Only present for the first replies of a fresh
 * run; the client stops sending it once the live relationship has data.
 *
 * Strictly tone. It never reaches AdvicePolicy, GameState or the safety layer:
 * a returning player gets recognised, not a different set of lies. Every field
 * is a bounded counter or one label from a closed list, so a modified client
 * can only change how warmly he greets them.
 */
final class RunHistory
{
    public const MAX_COUNTER = 9999;

    public const ENDINGS = [
        'escape_together',
        'obedient_fool',
        'cold_betrayal',
        'paranoid_survivor',
        'merged_memory',
        'the_replacement',
    ];

    public const TONES = ['warm', 'neutral', 'cold'];

    public function __construct(
        private readonly int $runsFinished,
        private readonly ?string $lastEnding,
        private readonly int $lastRunCalls,
        private readonly string $lastRunTone,
        private readonly int $liesTold,
        private readonly int $caughtLying,
        private readonly int $runsFollowingHim,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $runs = self::counter($raw['runs_finished'] ?? null);
        if ($runs <= 0) {
            return null;
        }

        $ending = null;
        if (isset($raw['last_ending']) && is_string($raw['last_ending'])) {
            $candidate = strtolower(trim($raw['last_ending']));
            $ending = in_array($candidate, self::ENDINGS, true) ? $candidate : null;
        }

        $tone = 'neutral';
        if (isset($raw['last_run_tone']) && is_string($raw['last_run_tone'])) {
            $candidate = strtolower(trim($raw['last_run_tone']));
            $tone = in_array($candidate, self::TONES, true) ? $candidate : 'neutral';
        }

        return new self(
            runsFinished: $runs,
            lastEnding: $ending,
            lastRunCalls: self::counter($raw['last_run_calls'] ?? null),
            lastRunTone: $tone,
            liesTold: self::counter($raw['lies_told'] ?? null),
            caughtLying: self::counter($raw['caught_lying'] ?? null),
            runsFollowingHim: self::counter($raw['runs_following_him'] ?? null),
        );
    }

    public function runsFinished(): int
    {
        return $this->runsFinished;
    }

    public function lastEnding(): ?string
    {
        return $this->lastEnding;
    }

    public function lastRunCalls(): int
    {
        return $this->lastRunCalls;
    }

    public function lastRunTone(): string
    {
        return $this->lastRunTone;
    }

    public function liesTold(): int
    {
        return $this->liesTold;
    }

    public function caughtLying(): int
    {
        return $this->caughtLying;
    }

    public function runsFollowingHim(): int
    {
        return $this->runsFollowingHim;
    }

    /**
     * Fixed-key projection for the prompt; only whitelisted labels and clamped
     * integers ever reach the model.
     *
     * @return array<string, int|string>
     */
    public function toPromptArray(): array
    {
        $out = [
            'runs_finished' => $this->runsFinished,
            'last_run_calls' => $this->lastRunCalls,
            'last_run_tone' => $this->lastRunTone,
            'lies_told' => $this->liesTold,
            'caught_lying' => $this->caughtLying,
            'runs_following_him' => $this->runsFollowingHim,
        ];
        if ($this->lastEnding !== null) {
            $out['last_ending'] = $this->lastEnding;
        }

        return $out;
    }

    private static function counter(mixed $value): int
    {
        if (!is_int($value) && !(is_float($value) && is_finite($value))) {
            return 0;
        }

        return max(0, min(self::MAX_COUNTER, (int) $value));
    }
}
