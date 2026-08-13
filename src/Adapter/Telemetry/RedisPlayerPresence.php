<?php

declare(strict_types=1);

namespace App\Adapter\Telemetry;

use App\Model\Telemetry\CountersUnavailable;
use App\Model\Telemetry\PlayerPresence;
use Psr\Log\LoggerInterface;

/**
 * Presence as a sorted set of player marks scored by the second they were last seen.
 *
 * A set rather than a counter because the same player pressing send four times is one player.
 * Old marks are dropped on read, so the set stays the size of a day's audience instead of
 * growing for the lifetime of the deployment.
 */
final class RedisPlayerPresence implements PlayerPresence
{
    private const KEY = 'loop9:presence';
    /** Long enough to cover a player thinking between messages, short enough to mean "now". */
    private const ONLINE_SECONDS = 300;
    private const DAY_SECONDS = 86400;

    public function __construct(
        private readonly \Redis|\RedisCluster $redis,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function seen(string $playerId): void
    {
        try {
            $this->redis->zAdd(self::KEY, time(), $this->member($playerId));
            // A safety net for the pruning below: an abandoned deployment leaves nothing behind.
            $this->redis->expire(self::KEY, self::DAY_SECONDS * 2);
        } catch (\Throwable $exception) {
            $this->logger->warning('Presence write failed.', [
                'exceptionClass' => $exception::class,
            ]);
        }
    }

    public function counts(): array
    {
        $now = time();

        try {
            $this->redis->zRemRangeByScore(self::KEY, '-inf', (string) ($now - self::DAY_SECONDS));

            $online = $this->redis->zCount(self::KEY, $now - self::ONLINE_SECONDS, '+inf');
            $day = $this->redis->zCard(self::KEY);
        } catch (\Throwable $exception) {
            throw new CountersUnavailable('Presence storage unreachable.', 0, $exception);
        }

        return [
            'online' => max(0, (int) $online),
            'day' => max(0, (int) $day),
        ];
    }

    /**
     * The set answers how many, never who, so the mark does not need to be reversible.
     */
    private function member(string $playerId): string
    {
        return substr(hash('sha256', $playerId), 0, 32);
    }
}
