<?php

declare(strict_types=1);

namespace App\Model\Chat;

/**
 * Where the active anomaly is and what kind of thing it is, as reported by the
 * Unreal client.
 *
 * The type alone leaves the model nothing to be specific about, so it fills the
 * gap by inventing a location. An invented location that the player checks and
 * finds normal reads exactly like the deliberate misdirection the game only
 * grants against a rude, dependent player, which quietly corrupts the trust and
 * suspicion axes. Carrying a coarse zone and an object category lets the model
 * be specific and truthful without naming the exact item the player must find.
 *
 * Contract (from Loop9BackendChatService):
 * - zone: coarse English place key, e.g. "east corridor". Empty when the
 *   anomaly has no place, as with a phantom chat message.
 * - object: English category noun, e.g. "office chair". Never an actor name.
 */
final class AnomalyDetail
{
    public const MAX_FIELD_LENGTH = 48;

    public function __construct(
        private readonly ?string $zone = null,
        private readonly ?string $object = null,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): ?self
    {
        $zone = self::sanitize($raw['zone'] ?? null);
        $object = self::sanitize($raw['object'] ?? null);

        if ($zone === null && $object === null) {
            return null;
        }

        return new self(zone: $zone, object: $object);
    }

    public function zone(): ?string
    {
        return $this->zone;
    }

    public function object(): ?string
    {
        return $this->object;
    }

    /**
     * Client text reaches the prompt, so control characters and newlines are
     * dropped: a newline would let a modified client end the untrusted line and
     * start one that reads like a directive.
     */
    private static function sanitize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $collapsed = preg_replace('/[\p{Cc}\p{Cf}\s]+/u', ' ', $value);
        if (!is_string($collapsed)) {
            return null;
        }

        $trimmed = trim($collapsed);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, self::MAX_FIELD_LENGTH);
    }
}
