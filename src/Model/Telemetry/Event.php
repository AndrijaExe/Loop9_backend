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
    /** A player crossed the daily watch line — more chats than a normal run. */
    public const ABUSE_WATCH = 'abuse.watch';
    public const API_ERRORS = 'api.errors';
    public const AI_FALLBACK = 'ai.fallback';
    public const AI_FAILED = 'ai.failed';
    /** Prompt tokens the provider billed, including answers we then discarded. */
    public const AI_TOKENS_IN = 'ai.tokens.in';
    /** Completion tokens the provider billed, including answers we then discarded. */
    public const AI_TOKENS_OUT = 'ai.tokens.out';
    /** Estimated spend in millionths of a dollar, so an integer counter can hold a price. */
    public const AI_COST_MICROS = 'ai.cost.micros';
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

    /**
     * Why a chat was refused. The reasons are a closed set, so this cannot grow unbounded.
     */
    public static function chatDenied(string $reason): string
    {
        return self::CHAT_DENIED.'.'.$reason;
    }

    public static function tokensInFor(string $vendor): string
    {
        return self::AI_TOKENS_IN.'.'.$vendor;
    }

    public static function tokensOutFor(string $vendor): string
    {
        return self::AI_TOKENS_OUT.'.'.$vendor;
    }

    public static function costMicrosFor(string $vendor): string
    {
        return self::AI_COST_MICROS.'.'.$vendor;
    }

    private function __construct()
    {
    }
}
