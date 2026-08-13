<?php

declare(strict_types=1);

namespace App\Adapter\Telemetry;

use App\Model\Telemetry\CountersUnavailable;
use App\Model\Telemetry\EventCounters;
use Psr\Log\LoggerInterface;

/**
 * Counts in a single Redis hash, so the numbers survive a restart and hold across instances.
 *
 * HINCRBY is atomic, which read-modify-write through a cache pool would not be. Two chat
 * requests landing in the same millisecond must not cost a count.
 */
final class RedisEventCounters implements EventCounters
{
    private const KEY = 'loop9:events';

    public function __construct(
        private readonly \Redis|\RedisCluster $redis,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function storage(): string
    {
        return 'redis';
    }

    public function increment(string $event, int $by = 1): void
    {
        try {
            $this->redis->hIncrBy(self::KEY, $event, $by);
        } catch (\Throwable $exception) {
            // A player's message must not fail because a counter could not be written.
            $this->logger->warning('Event counter write failed.', [
                'event' => $event,
                'exceptionClass' => $exception::class,
            ]);
        }
    }

    public function totals(): array
    {
        try {
            $raw = $this->redis->hGetAll(self::KEY);
        } catch (\Throwable $exception) {
            throw new CountersUnavailable('Counter storage unreachable.', 0, $exception);
        }

        if (!is_array($raw)) {
            return [];
        }

        $totals = [];
        foreach ($raw as $event => $value) {
            $totals[(string) $event] = (int) $value;
        }

        ksort($totals);

        return $totals;
    }
}
