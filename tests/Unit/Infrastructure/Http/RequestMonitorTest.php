<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Http;

use App\Infrastructure\Http\RequestMonitor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class RequestMonitorTest extends TestCase
{
    public function testPreservesValidClientRequestId(): void
    {
        $request = new Request();
        $request->headers->set('X-Request-Id', '77b35058-34ae-43bc-bdb9-565656101e91');

        self::assertSame(
            '77b35058-34ae-43bc-bdb9-565656101e91',
            RequestMonitor::requestId($request)
        );
    }

    public function testReplacesUnsafeClientRequestId(): void
    {
        $request = new Request();
        $request->headers->set('X-Request-Id', "forged\nlog-entry");

        $requestId = RequestMonitor::requestId($request);

        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/', $requestId);
        self::assertSame($requestId, $request->headers->get('X-Request-Id'));
    }
}
