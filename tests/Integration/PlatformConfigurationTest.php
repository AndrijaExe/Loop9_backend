<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Adapter\AI\OpenAiCompatibleAiChatGateway;
use App\Adapter\AI\OpenAiContentSafetyGateway;
use App\Adapter\Http\ChatController;
use App\Adapter\Http\RunTelemetryController;
use App\Adapter\Http\SteamAuthController;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Yaml\Yaml;

final class PlatformConfigurationTest extends KernelTestCase
{
    private const LIMITERS = [
        'game_chat',
        'game_ip_daily',
        'player_daily_quota',
        'player_monthly_quota',
        'auth_steam',
        'telemetry_ip',
        'game_global_daily',
    ];

    public function testAllRateLimitersUseSafeLocalLocksAndProductionUsesRedis(): void
    {
        self::bootKernel();

        foreach (self::LIMITERS as $name) {
            $limiter = static::getContainer()->get('limiter.' . $name);
            self::assertInstanceOf(RateLimiterFactory::class, $limiter);

            $lockFactory = $this->privateProperty($limiter, 'lockFactory');
            self::assertInstanceOf(LockFactory::class, $lockFactory);
            self::assertInstanceOf(FlockStore::class, $this->privateProperty($lockFactory, 'store'));
        }

        $lockConfig = Yaml::parseFile(self::getContainer()->getParameter('kernel.project_dir') . '/config/packages/lock.yaml');
        self::assertSame('flock', $lockConfig['framework']['lock'] ?? null);
        self::assertSame('%env(REDIS_URL)%', $lockConfig['when@prod']['framework']['lock'] ?? null);

        $limiterConfig = Yaml::parseFile(self::getContainer()->getParameter('kernel.project_dir') . '/config/packages/rate_limiter.yaml');
        foreach (self::LIMITERS as $name) {
            self::assertSame(
                'lock.factory',
                $limiterConfig['framework']['rate_limiter'][$name]['lock_factory'] ?? null,
            );
        }
    }

    #[DataProvider('telemetryServiceProvider')]
    public function testSanitizedOperationalServicesUseTelemetryLogger(string $serviceId): void
    {
        self::bootKernel();

        $telemetryLogger = static::getContainer()->get('monolog.logger.telemetry');
        self::assertInstanceOf(Logger::class, $telemetryLogger);
        self::assertSame('telemetry', $telemetryLogger->getName());

        $service = static::getContainer()->get($serviceId);
        self::assertSame($telemetryLogger, $this->privateProperty($service, 'logger'));
    }

    public function testProductionTelemetryHandlerIsJsonStderrAndHttpClientRemainsExcluded(): void
    {
        self::bootKernel();
        $config = Yaml::parseFile(self::getContainer()->getParameter('kernel.project_dir') . '/config/packages/monolog.yaml');
        $handlers = $config['when@prod']['monolog']['handlers'] ?? [];

        self::assertSame('php://stderr', $handlers['telemetry']['path'] ?? null);
        self::assertSame('info', $handlers['telemetry']['level'] ?? null);
        self::assertSame(['telemetry'], $handlers['telemetry']['channels'] ?? null);
        self::assertSame('monolog.formatter.json', $handlers['telemetry']['formatter'] ?? null);
        self::assertContains('!http_client', $handlers['main']['channels'] ?? []);
        self::assertContains('!telemetry', $handlers['main']['channels'] ?? []);
    }

    public function testDockerRuntimeDefaultsToHardenedProductionSettings(): void
    {
        self::bootKernel();
        $dockerfile = file_get_contents(
            self::getContainer()->getParameter('kernel.project_dir') . '/Dockerfile',
        );

        self::assertIsString($dockerfile);
        self::assertStringContainsString('APP_ENV=prod', $dockerfile);
        self::assertStringContainsString('APP_DEBUG=0', $dockerfile);
        self::assertStringContainsString("'register_argc_argv=0'", $dockerfile);
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function telemetryServiceProvider(): iterable
    {
        yield 'run telemetry' => [RunTelemetryController::class];
        yield 'Steam auth timing' => [SteamAuthController::class];
        yield 'chat timing' => [ChatController::class];
        yield 'AI timing' => [OpenAiCompatibleAiChatGateway::class];
        yield 'moderation timing' => [OpenAiContentSafetyGateway::class];
    }

    private function privateProperty(object $object, string $name): mixed
    {
        $property = new \ReflectionProperty($object, $name);

        return $property->getValue($object);
    }
}
