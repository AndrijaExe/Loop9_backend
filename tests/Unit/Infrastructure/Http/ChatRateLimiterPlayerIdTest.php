<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Http;

use App\Infrastructure\Http\ChatRateLimiter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class ChatRateLimiterPlayerIdTest extends TestCase
{
    public function testAcceptsValidPlayerIdFromHeader(): void
    {
        $limiter = $this->createLimiter();
        $request = Request::create('/api/chat', 'POST', server: ['HTTP_X_PLAYER_ID' => 'player-42-ab']);

        self::assertSame('player-42-ab', $limiter->resolvePlayerId([], $request));
    }

    public function testRejectsShortPlayerId(): void
    {
        $limiter = $this->createLimiter();
        $request = Request::create('/api/chat', 'POST');

        $this->expectException(BadRequestHttpException::class);
        $limiter->resolvePlayerId(['player_id' => 'short'], $request);
    }

    public function testRejectsInvalidCharacters(): void
    {
        $limiter = $this->createLimiter();
        $request = Request::create('/api/chat', 'POST');

        $this->expectException(BadRequestHttpException::class);
        $limiter->resolvePlayerId(['player_id' => 'bad player id!!'], $request);
    }

    public function testGlobalDailyQuotaBlocksWhenExhausted(): void
    {
        $storage = new InMemoryStorage();
        $limiter = new ChatRateLimiter(
            $this->makeFactory('burst', $storage),
            $this->makeFactory('ip-daily', $storage),
            $this->makeFactory('player-daily', $storage),
            $this->makeFactory('player-monthly', $storage),
            new RateLimiterFactory([
                'id' => 'global-daily',
                'policy' => 'fixed_window',
                'limit' => 1,
                'interval' => '1 day',
            ], $storage),
        );

        self::assertNull($limiter->enforceGlobalDailyQuota());

        $denied = $limiter->enforceGlobalDailyQuota();
        self::assertNotNull($denied);
        self::assertSame(429, $denied->getStatusCode());
    }

    private function createLimiter(): ChatRateLimiter
    {
        $storage = new InMemoryStorage();

        return new ChatRateLimiter(
            $this->makeFactory('burst', $storage),
            $this->makeFactory('ip-daily', $storage),
            $this->makeFactory('player-daily', $storage),
            $this->makeFactory('player-monthly', $storage),
            $this->makeFactory('global-daily', $storage),
        );
    }

    private function makeFactory(string $id, InMemoryStorage $storage): RateLimiterFactory
    {
        return new RateLimiterFactory([
            'id' => $id,
            'policy' => 'fixed_window',
            'limit' => 1000,
            'interval' => '1 day',
        ], $storage);
    }
}
