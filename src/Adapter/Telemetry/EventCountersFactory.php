<?php

declare(strict_types=1);

namespace App\Adapter\Telemetry;

use App\Model\Telemetry\EventCounters;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;

final class EventCountersFactory
{
    public static function create(string $redisUrl, LoggerInterface $logger): EventCounters
    {
        if (trim($redisUrl) === '') {
            return new InMemoryEventCounters();
        }

        try {
            // Lazy, so booting the kernel never opens a socket. /healthz must stay
            // independent of Redis, and most requests never touch a counter at all.
            $client = RedisAdapter::createConnection($redisUrl, ['lazy' => true]);
        } catch (\Throwable $exception) {
            $logger->warning('Counter storage could not be configured; counting in memory.', [
                'exceptionClass' => $exception::class,
            ]);

            return new InMemoryEventCounters();
        }

        if (!$client instanceof \Redis && !$client instanceof \RedisCluster) {
            return new InMemoryEventCounters();
        }

        return new RedisEventCounters($client, $logger);
    }
}
