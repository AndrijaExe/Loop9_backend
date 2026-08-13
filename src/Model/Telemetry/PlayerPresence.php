<?php

declare(strict_types=1);

namespace App\Model\Telemetry;

/**
 * How many people are actually playing.
 *
 * This is the one number a health probe can never produce: a service answering /healthz with
 * nobody in it looks exactly like one carrying a thousand players. Unlike the event counters
 * it is a level rather than a total, so it is read as "now", never summed over a day.
 */
interface PlayerPresence
{
    /**
     * Marks a player as active at this moment.
     *
     * Must not throw. A lost mark costs one number on a dashboard; a player's request is worth
     * more than that.
     */
    public function seen(string $playerId): void;

    /**
     * @return array{online: int, day: int} active in the last few minutes, and distinct in 24h
     *
     * @throws CountersUnavailable when the storage cannot be read
     */
    public function counts(): array;
}
