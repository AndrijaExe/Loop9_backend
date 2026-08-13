<?php

declare(strict_types=1);

namespace App\Model\Telemetry;

/**
 * Cumulative counts of the events this service already considers worth logging.
 *
 * Counters only ever go up. Nothing here resets them on a schedule, because a reader that
 * wants "the last hour" can subtract two readings, while a reader that arrives after a reset
 * has lost the interval for good. A drop in the total therefore means the store was cleared,
 * not that anything went backwards.
 */
interface EventCounters
{
    public function increment(string $event, int $by = 1): void;

    /**
     * @return array<string, int>
     *
     * @throws CountersUnavailable
     */
    public function totals(): array;
}
