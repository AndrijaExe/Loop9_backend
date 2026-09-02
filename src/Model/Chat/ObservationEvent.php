<?php

declare(strict_types=1);

namespace App\Model\Chat;

final class ObservationEvent
{
    public const int MAX_COUNT = 255;
    public const int MAX_AGE_SECONDS = 65535;

    /** @var list<string> */
    private const array ALLOWED_TYPES = [
        'zone_entered',
        'object_inspected',
        'door_opened',
        'door_closed',
        'door_denied',
        'flashlight_on',
        'flashlight_off',
        'pursuer_observed',
        'pursuer_caught',
        'call_completed',
    ];

    public function __construct(
        private readonly string $type,
        private readonly ?string $zone,
        private readonly ?string $subject,
        private readonly int $count,
        private readonly int $ageSeconds,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $type = $raw['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::ALLOWED_TYPES, true)) {
            return null;
        }

        return new self(
            type: $type,
            zone: ObservationSnapshot::sanitizeIdentifier($raw['zone'] ?? null),
            subject: ObservationSnapshot::sanitizeIdentifier($raw['subject'] ?? null),
            count: self::boundedInteger($raw['count'] ?? 1, 1, self::MAX_COUNT, 1),
            ageSeconds: self::boundedInteger($raw['age_seconds'] ?? 0, 0, self::MAX_AGE_SECONDS, 0),
        );
    }

    /**
     * @return array{type: string, zone?: string, subject?: string, count: int, age_seconds: int}
     */
    public function toPromptArray(): array
    {
        $event = ['type' => $this->type];
        if ($this->zone !== null) {
            $event['zone'] = $this->zone;
        }
        if ($this->subject !== null) {
            $event['subject'] = $this->subject;
        }
        $event['count'] = $this->count;
        $event['age_seconds'] = $this->ageSeconds;

        return $event;
    }

    private static function boundedInteger(mixed $value, int $min, int $max, int $default): int
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }
}
