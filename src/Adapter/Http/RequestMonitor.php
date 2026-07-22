<?php

declare(strict_types=1);

namespace App\Adapter\Http;

use Symfony\Component\HttpFoundation\Request;

final class RequestMonitor
{
    public static function requestId(Request $request): string
    {
        $candidate = trim((string) $request->headers->get('X-Request-Id', ''));
        $requestId = preg_match('/\A[A-Fa-f0-9-]{16,64}\z/', $candidate) === 1
            ? $candidate
            : bin2hex(random_bytes(16));

        // Downstream services can use the validated/generated id without
        // ever trusting a raw client header in logs.
        $request->headers->set('X-Request-Id', $requestId);

        return $requestId;
    }

    public static function elapsedMs(int $startedAt): float
    {
        return round((hrtime(true) - $startedAt) / 1_000_000, 2);
    }
}
