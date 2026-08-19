<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\Telemetry;

use App\Adapter\Telemetry\InMemoryChatVolume;
use PHPUnit\Framework\TestCase;

final class InMemoryChatVolumeTest extends TestCase
{
    public function testTheHeaviestPlayerIsTheOneWhoSentTheMost(): void
    {
        $volume = new InMemoryChatVolume(watchAfter: 3);
        $volume->recorded('steam-quiet');
        $volume->recorded('steam-hot');
        $volume->recorded('steam-hot');
        $volume->recorded('steam-hot');

        self::assertSame(['heaviest' => 3, 'hot' => 1], $volume->snapshot());
    }

    public function testAQuietDayReportsZeros(): void
    {
        self::assertSame(['heaviest' => 0, 'hot' => 0], (new InMemoryChatVolume())->snapshot());
    }
}
