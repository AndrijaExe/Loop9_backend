<?php

declare(strict_types=1);

namespace App\Adapter\Telemetry;

use App\Model\Telemetry\PlayerPresence;

/**
 * Presence for the length of one request, which is all a machine without Redis can offer.
 *
 * In production this would report at most the single player whose request is being served, so
 * the metrics payload names its storage and the console can say the number is not to be trusted.
 */
final class InMemoryPlayerPresence implements PlayerPresence
{
    /** @var array<string, int> */
    private array $seen = [];

    public function seen(string $playerId): void
    {
        $this->seen[$playerId] = time();
    }

    public function counts(): array
    {
        $count = count($this->seen);

        return ['online' => $count, 'day' => $count];
    }
}
