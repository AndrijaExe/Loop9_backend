<?php

declare(strict_types=1);

namespace App\Adapter\Telemetry;

use App\Model\Telemetry\EventCounters;
use Psr\Log\LoggerInterface;

final class EventCountersFactory
{
    public static function create(string $redisUrl, LoggerInterface $logger): EventCounters
    {
        $client = RedisConnection::open($redisUrl, $logger);

        return $client === null
            ? new InMemoryEventCounters()
            : new RedisEventCounters($client, $logger);
    }
}
