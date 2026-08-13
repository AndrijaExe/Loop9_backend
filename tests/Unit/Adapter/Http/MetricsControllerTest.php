<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\Http;

use App\Adapter\Http\MetricsController;
use App\Adapter\Telemetry\InMemoryEventCounters;
use App\Model\Telemetry\CountersUnavailable;
use App\Model\Telemetry\EventCounters;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MetricsControllerTest extends TestCase
{
    public function testAnUnconfiguredEndpointDoesNotExist(): void
    {
        $controller = new MetricsController(new InMemoryEventCounters(), '');

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

            public function totals(): array
            {
                throw new CountersUnavailable('nope');
            }
        };

        $request = new Request();
        $request->headers->set('X-Metrics-Token', 'secret');

        $response = (new MetricsController($counters, 'secret'))($request);

        self::assertSame(503, $response->getStatusCode());
    }

    public function testCountersAreServedAsAnObjectEvenWhenEmpty(): void
    {
        $request = new Request();
        $request->headers->set('X-Metrics-Token', 'secret');

        $response = (new MetricsController(new InMemoryEventCounters(), 'secret'))($request);

        // An empty PHP array encodes as [], and a reader expecting a map would choke.
        self::assertStringContainsString('"counters":{}', (string) $response->getContent());
    }
}
