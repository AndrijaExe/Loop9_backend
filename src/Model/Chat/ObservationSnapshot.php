<?php

declare(strict_types=1);

namespace App\Model\Chat;

final class ObservationSnapshot
{
    public const int MAX_IDENTIFIER_LENGTH = 48;
    public const int MAX_EVENTS = 8;
    public const int MAX_VISITED_ZONES = 8;
    public const int MAX_SECONDS_ON_FLOOR = 65535;
    public const int MAX_RUN_COUNT = 65535;

    /**
     * @param list<ObservationEvent> $events
     * @param list<string> $visitedZones
     * @param array{floors_started: int, ai_interactions: int, elevator_decisions: int, correct_decisions: int} $runSummary
     */
    public function __construct(
        private readonly ?string $currentZone,
        private readonly int $secondsOnFloor,
        private readonly array $events,
        private readonly array $visitedZones,
        private readonly array $runSummary,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $events = [];
        if (isset($raw['events']) && is_array($raw['events'])) {
            foreach ($raw['events'] as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $event = ObservationEvent::fromArray($candidate);
                if ($event !== null) {
                    $events[] = $event;
                    if (count($events) === self::MAX_EVENTS) {
                        break;
                    }
                }
            }
        }

        $visitedZones = [];
        if (isset($raw['visited_zones']) && is_array($raw['visited_zones'])) {
            foreach ($raw['visited_zones'] as $candidate) {
                $zone = self::sanitizeIdentifier($candidate);
                if ($zone === null || in_array($zone, $visitedZones, true)) {
                    continue;
                }

                $visitedZones[] = $zone;
                if (count($visitedZones) === self::MAX_VISITED_ZONES) {
                    break;
                }
            }
        }

        $summary = is_array($raw['run_summary'] ?? null) ? $raw['run_summary'] : [];

        return new self(
            currentZone: self::sanitizeIdentifier($raw['current_zone'] ?? null),
            secondsOnFloor: self::boundedInteger(
                $raw['seconds_on_floor'] ?? 0,
                0,
                self::MAX_SECONDS_ON_FLOOR,
            ),
            events: $events,
            visitedZones: $visitedZones,
            runSummary: [
                'floors_started' => self::boundedInteger(
                    $summary['floors_started'] ?? 0,
                    0,
                    self::MAX_RUN_COUNT,
                ),
                'ai_interactions' => self::boundedInteger(
                    $summary['ai_interactions'] ?? 0,
                    0,
                    self::MAX_RUN_COUNT,
                ),
                'elevator_decisions' => self::boundedInteger(
                    $summary['elevator_decisions'] ?? 0,
                    0,
                    self::MAX_RUN_COUNT,
                ),
                'correct_decisions' => self::boundedInteger(
                    $summary['correct_decisions'] ?? 0,
                    0,
                    self::MAX_RUN_COUNT,
                ),
            ],
        );
    }

    public static function sanitizeIdentifier(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = mb_strtolower(trim($value));
        $normalized = preg_replace('/[\p{Cc}\p{Cf}\s]+/u', '_', $normalized);
        if (!is_string($normalized)) {
            return null;
        }

        // These are authored identifiers, not labels or free-form prose.
        // Mirroring the game-side slug contract removes instruction-shaped
        // punctuation before the value ever reaches the prompt.
        $normalized = preg_replace('/[^a-z0-9_-]+/', '', $normalized);
        $normalized = preg_replace('/[_-]{2,}/', '_', is_string($normalized) ? $normalized : '');
        if (!is_string($normalized)) {
            return null;
        }

        $normalized = trim($normalized, '_-');

        return $normalized === '' ? null : substr($normalized, 0, self::MAX_IDENTIFIER_LENGTH);
    }

    /**
     * Fixed key order is intentional: the same snapshot always produces the
     * same compact prompt representation.
     *
     * @return array{
     *   current_zone: string|null,
     *   seconds_on_floor: int,
     *   events: list<array<string, int|string>>,
     *   visited_zones: list<string>,
     *   run_summary: array{floors_started: int, ai_interactions: int, elevator_decisions: int, correct_decisions: int}
     * }
     */
    public function toPromptArray(): array
    {
        return [
            'current_zone' => $this->currentZone,
            'seconds_on_floor' => $this->secondsOnFloor,
            'events' => array_map(
                static fn (ObservationEvent $event): array => $event->toPromptArray(),
                $this->events,
            ),
            'visited_zones' => $this->visitedZones,
            'run_summary' => $this->runSummary,
        ];
    }

    private static function boundedInteger(mixed $value, int $min, int $max): int
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return $min;
        }

        return max($min, min($max, (int) $value));
    }

}
