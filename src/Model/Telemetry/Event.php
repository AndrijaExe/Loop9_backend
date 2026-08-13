<?php

declare(strict_types=1);

namespace App\Model\Telemetry;

/**
 * The counted events, named in one place so a reading can be compared across deploys.
 *
 * Renaming one of these starts a new series from zero, so treat the strings as a published
 * contract rather than as labels.
 */
final class Event
{
    public const CHAT_MESSAGES = 'chat.messages';
    public const CHAT_DENIED = 'chat.denied';
    public const API_ERRORS = 'api.errors';
    public const AI_FALLBACK = 'ai.fallback';
    public const AI_FAILED = 'ai.failed';
    public const SAFETY_BLOCKED = 'safety.blocked';
    public const SAFETY_UNAVAILABLE = 'safety.unavailable';
    public const AUTH_ISSUED = 'auth.issued';
    public const AUTH_REJECTED = 'auth.rejected';
    public const RUN_ENDED = 'run.ended';

    /**
     * Endings are a closed set of six ids, so counting them by name cannot grow unbounded.
     */
    public static function runEnding(string $ending): string
    {
        return self::RUN_ENDED.'.'.$ending;
    }

    private function __construct()
    {
    }
}
