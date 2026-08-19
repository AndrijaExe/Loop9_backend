<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model\Telemetry;

use App\Model\Telemetry\AiVendor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AiVendorTest extends TestCase
{
    #[DataProvider('hosts')]
    public function testTheBilledHostBecomesTheVendorName(string $url, string $vendor): void
    {
        self::assertSame($vendor, AiVendor::fromUrl($url));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function hosts(): iterable
    {
        yield 'openai' => ['https://api.openai.com/v1/chat/completions', 'openai'];
        yield 'azure openai' => ['https://loop9.openai.azure.com/openai/deployments/x', 'openai'];
        yield 'gemini' => ['https://generativelanguage.googleapis.com/v1beta/openai/chat/completions', 'gemini'];
        yield 'groq' => ['https://api.groq.com/openai/v1/chat/completions', 'groq'];
        yield 'unknown host' => ['https://example.test/v1/chat/completions', 'example'];
        yield 'empty' => ['', 'unknown'];
        yield 'not a url' => ['not-a-url', 'unknown'];
    }
}
