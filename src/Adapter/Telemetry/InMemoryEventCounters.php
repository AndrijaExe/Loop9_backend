<?php

declare(strict_types=1);

namespace App\Adapter\Telemetry;

use App\Model\Telemetry\EventCounters;

/**
 * Counts for the length of one request, which is all a machine without Redis can honestly
 * offer. Used in tests and in local runs that skip the container.
 */
final class InMemoryEventCounters implements EventCounters
{
    /** @var array<string, int> */
    private array $totals = [];

    public function storage(): string
    {
        return 'memory';
    }

    public function increment(string $event, int $by = 1): void
    {
        $this->totals[$event] = ($this->totals[$event] ?? 0) + $by;
    }

    public function totals(): array
    {
        $totals = $this->totals;
        ksort($totals);

        return $totals;
    }
}
