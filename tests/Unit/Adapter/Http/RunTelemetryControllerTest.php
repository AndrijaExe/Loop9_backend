<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\Http;

use App\Adapter\Telemetry\InMemoryEventCounters;
use App\Adapter\Auth\SessionTokenIssuer;
use App\Adapter\Http\GameTokenAuthenticator;
use App\Adapter\Http\RunTelemetryController;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RunTelemetryControllerTest extends TestCase
{
    public function testLogsTheStableTelemetryContract(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('info')
            ->with('Run telemetry.', [
                'ending' => 'paranoid_survivor',
                'resets' => 4,
                'aiMessages' => 12,
                'build' => '1.0.0',
            ]);

        $controller = new RunTelemetryController(
            new GameTokenAuthenticator(
                gameApiToken: 'test-token',
                allowGameToken: true,
                appEnv: 'test',
                sessionTokens: new SessionTokenIssuer('test-session-secret', 3600),
            ),
            new RateLimiterFactory([
                'id' => 'telemetry',
                'policy' => 'fixed_window',
                'limit' => 30,
                'interval' => '1 hour',
            ], new InMemoryStorage()),
            $logger,
            new InMemoryEventCounters(),
        );

        $request = Request::create(
            '/api/telemetry/run',
            'POST',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_GAME_TOKEN' => 'test-token',
            ],
            content: json_encode([
                'ending' => 'paranoid_survivor',
                'resets' => 4,
                'ai_messages' => 12,
                'build' => '1.0.0',
            ], JSON_THROW_ON_ERROR),
        );

        self::assertSame(204, $controller($request)->getStatusCode());
    }
}
