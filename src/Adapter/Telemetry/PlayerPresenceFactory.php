<?php

declare(strict_types=1);

namespace App\Adapter\Telemetry;

use App\Model\Telemetry\PlayerPresence;
use Psr\Log\LoggerInterface;

final class PlayerPresenceFactory
{
    public static function create(string $redisUrl, LoggerInterface $logger): PlayerPresence
    {
        $client = RedisConnection::open($redisUrl, $logger);

        return $client === null
            ? new InMemoryPlayerPresence()
            : new RedisPlayerPresence($client, $logger);
    }
}
