<?php

declare(strict_types=1);

namespace App\Adapter\Telemetry;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * One place that turns REDIS_URL into a client, or into nothing.
 *
 * Telemetry must never be the reason a request fails, so a machine without Redis gets a null
 * here and the caller falls back to counting in memory.
 */
final class RedisConnection
{
    public static function open(string $redisUrl, LoggerInterface $logger): \Redis|\RedisCluster|null
    {
        if (trim($redisUrl) === '') {
            return null;
        }

        try {
            // Lazy, so booting the kernel never opens a socket. /healthz must stay independent
            // of Redis, and most requests never touch telemetry at all.
            $client = RedisAdapter::createConnection($redisUrl, ['lazy' => true]);
        } catch (\Throwable $exception) {
            $logger->warning('Telemetry storage could not be configured; falling back to memory.', [
                'exceptionClass' => $exception::class,
            ]);

            return null;
        }

        return $client instanceof \Redis || $client instanceof \RedisCluster ? $client : null;
    }

    private function __construct()
    {
    }
}
