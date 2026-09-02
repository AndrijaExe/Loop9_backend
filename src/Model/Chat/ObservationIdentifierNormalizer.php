<?php

declare(strict_types=1);

namespace App\Model\Chat;

final class ObservationIdentifierNormalizer
{
    public const int MAX_LENGTH = 48;

    public static function normalize(mixed $value): ?string
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

        return $normalized === '' ? null : substr($normalized, 0, self::MAX_LENGTH);
    }

    private function __construct()
    {
    }
}
