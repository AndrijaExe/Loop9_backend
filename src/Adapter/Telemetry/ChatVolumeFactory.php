<?php

declare(strict_types=1);

namespace App\Adapter\Telemetry;

use App\Model\Telemetry\ChatVolume;
use Psr\Log\LoggerInterface;

final class ChatVolumeFactory
{
    public static function create(string $redisUrl, LoggerInterface $logger, int $watchAfter = 40): ChatVolume
    {
        $client = RedisConnection::open($redisUrl, $logger);

        return $client === null
            ? new InMemoryChatVolume($watchAfter)
            : new RedisChatVolume($client, $logger, $watchAfter);
    }
}
