<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class MetricsTest extends WebTestCase
{
    public function testRejectsMissingToken(): void
    {
        static::createClient()->request('GET', '/metrics');

        self::assertResponseStatusCodeSame(403);
    }

    public function testRejectsWrongToken(): void
    {
        static::createClient()->request('GET', '/metrics', server: [
            'HTTP_X_METRICS_TOKEN' => 'not-the-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testCountsRunEndingsUnderStableNames(): void
    {
        $client = static::createClient();
        // Without this the kernel reboots between requests and takes the in-memory
        // counters with it, which would test nothing.
        $client->disableReboot();

        $client->request(
            'POST',
            '/api/telemetry/run',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GAME_TOKEN' => 'change-this-token',
            ],
            content: json_encode(['ending' => 'cold_betrayal'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/metrics', server: [
            'HTTP_X_METRICS_TOKEN' => 'test-metrics-token',
        ]);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame(1, $payload['counters']['run.ended']);
        self::assertSame(1, $payload['counters']['run.ended.cold_betrayal']);
        self::assertNotSame('', $payload['at']);
    }
}
