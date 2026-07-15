<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RunTelemetryEndpointTest extends WebTestCase
{
    public function testRejectsMissingToken(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/telemetry/run',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ending' => 'escape_together'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testRejectsUnknownEnding(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/telemetry/run',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GAME_TOKEN' => $this->gameToken(),
            ],
            content: json_encode(['ending' => 'not-an-ending'], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testAcceptsValidRun(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/telemetry/run',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GAME_TOKEN' => $this->gameToken(),
            ],
            content: json_encode([
                'ending' => 'paranoid_survivor',
                'resets' => 4,
                'ai_messages' => 12,
                'build' => '1.0.0',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(204);
    }

    public function testOptionsRequestReturnsNoContent(): void
    {
        $client = static::createClient();

        $client->request('OPTIONS', '/api/telemetry/run');

        self::assertResponseStatusCodeSame(204);
    }

    private function gameToken(): string
    {
        return $_ENV['GAME_API_TOKEN'] ?? $_SERVER['GAME_API_TOKEN'] ?? 'test-token';
    }
}
