<?php

declare(strict_types=1);

namespace App\Model\Telemetry;

/**
 * How hard one player is leaning on the paid chat, today.
 *
 * A normal run is a handful of replies. A farmer burns the daily cap on one identity.
 * This answers the second question without saying who they are.
 */
interface ChatVolume
{
    /**
     * Records one billed chat and returns that player's count for the UTC day.
     *
     * Must not throw. A lost mark costs a dashboard number; the reply already went out.
     */
    public function recorded(string $playerId): int;

    /**
     * @return array{heaviest: int, hot: int} most chats by one player today, and how many
     *                                         players have crossed the watch line
     *
     * @throws CountersUnavailable when the storage cannot be read
     */
    public function snapshot(): array;
}
