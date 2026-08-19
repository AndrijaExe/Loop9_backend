<?php

declare(strict_types=1);

namespace App\Adapter\Telemetry;

use App\Model\Telemetry\ChatVolume;
use App\Model\Telemetry\CountersUnavailable;
use Psr\Log\LoggerInterface;

/**
 * Per-player chat counts for the current UTC day, as a sorted set of opaque marks.
 *
 * The set answers how hard, never who. It expires so a quiet day leaves nothing behind.
 */
final class RedisChatVolume implements ChatVolume
{
    private const KEY_PREFIX = 'loop9:chat:day:';
    private const TTL_SECONDS = 172800;

    public function __construct(
        private readonly \Redis|\RedisCluster $redis,
        private readonly LoggerInterface $logger,
        private readonly int $watchAfter = 40,
    ) {
    }

    public function recorded(string $playerId): int
    {
        try {
            $count = (int) $this->redis->zIncrBy($this->key(), 1.0, $this->member($playerId));
            $this->redis->expire($this->key(), self::TTL_SECONDS);

            return max(0, $count);
        } catch (\Throwable $exception) {
            $this->logger->warning('Chat volume write failed.', [
                'exceptionClass' => $exception::class,
            ]);

            return 0;
        }
    }

    public function snapshot(): array
    {
        try {
            $key = $this->key();
            $top = $this->redis->zRevRange($key, 0, 0, true);
            $heaviest = 0;
            if (is_array($top) && $top !== []) {
                $heaviest = (int) array_values($top)[0];
            }

            $hot = 0;
            if ($this->watchAfter > 0) {
                $hot = (int) $this->redis->zCount($key, (string) $this->watchAfter, '+inf');
            }

            return [
                'heaviest' => max(0, $heaviest),
                'hot' => max(0, $hot),
            ];
        } catch (\Throwable $exception) {
            throw new CountersUnavailable('Chat volume storage unreachable.', 0, $exception);
        }
    }

    private function key(): string
    {
        return self::KEY_PREFIX.gmdate('Ymd');
    }

    private function member(string $playerId): string
    {
        return substr(hash('sha256', $playerId), 0, 32);
    }
}
