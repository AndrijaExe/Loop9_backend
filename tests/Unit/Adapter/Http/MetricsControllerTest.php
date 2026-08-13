<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\Http;

use App\Adapter\Http\MetricsController;
use App\Adapter\Telemetry\InMemoryEventCounters;
use App\Adapter\Telemetry\InMemoryPlayerPresence;
use App\Model\Telemetry\CountersUnavailable;
use App\Model\Telemetry\EventCounters;
use App\Model\Telemetry\PlayerPresence;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MetricsControllerTest extends TestCase
{
    public function testAnUnconfiguredEndpointDoesNotExist(): void
    {
        $controller = new MetricsController(new InMemoryEventCounters(), new InMemoryPlayerPresence(), '');

        // Not 403: a server that has never been asked to publish counters should not
        // advertise a door for someone to start guessing tokens against.
        $this->expectException(NotFoundHttpException::class);

        $controller(new Request());
    }

    public function testUnreachableStorageIsReportedRatherThanFakedAsZero(): void
    {
        $counters = new class implements EventCounters {
            public function increment(string $event, int $by = 1): void
            {
            }

            public function storage(): string
            {
                return 'redis';
            }

            public function totals(): array
            {
                throw new CountersUnavailable('nope');
            }
        };

        $response = (new MetricsController($counters, new InMemoryPlayerPresence(), 'secret'))($this->asked());

        self::assertSame(503, $response->getStatusCode());
    }

    public function testUnreachablePresenceIsReportedToo(): void
    {
        $presence = new class implements PlayerPresence {
            public function seen(string $playerId): void
            {
            }

            public function counts(): array
            {
                throw new CountersUnavailable('nope');
            }
        };

        $response = (new MetricsController(new InMemoryEventCounters(), $presence, 'secret'))($this->asked());

        self::assertSame(503, $response->getStatusCode());
    }

    public function testCountersAreServedAsAnObjectEvenWhenEmpty(): void
    {
        $response = (new MetricsController(new InMemoryEventCounters(), new InMemoryPlayerPresence(), 'secret'))(
            $this->asked(),
        );

        // An empty PHP array encodes as [], and a reader expecting a map would choke.
        self::assertStringContainsString('"counters":{}', (string) $response->getContent());
    }

    public function testPlayersAndStorageTravelWithTheCounts(): void
    {
        $presence = new InMemoryPlayerPresence();
        $presence->seen('steam-1');
        $presence->seen('steam-2');
        $presence->seen('steam-1');

        $response = (new MetricsController(new InMemoryEventCounters(), $presence, 'secret'))($this->asked());
        $payload = json_decode((string) $response->getContent(), true);

        // The same player twice is one player.
        self::assertSame(2, $payload['gauges']['players.online']);
        // Zeros from a store that forgets read differently from zeros from a quiet day.
        self::assertSame('memory', $payload['storage']);
    }

    private function asked(): Request
    {
        $request = new Request();
        $request->headers->set('X-Metrics-Token', 'secret');

        return $request;
    }
}
