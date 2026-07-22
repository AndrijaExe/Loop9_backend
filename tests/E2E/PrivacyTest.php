<?php

declare(strict_types=1);

namespace App\Tests\E2E;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PrivacyTest extends WebTestCase
{
    public function testPrivacyPolicyIsPublicAndDescribesAiProcessing(): void
    {
        $client = static::createClient();
        $client->request('GET', '/privacy');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
        self::assertResponseHeaderSame(
            'Content-Security-Policy',
            "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'",
        );
        self::assertResponseHeaderSame('Referrer-Policy', 'no-referrer');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');

        $content = $client->getResponse()->getContent() ?: '';
        self::assertStringContainsString('Loop 9 Privacy Policy', $content);
        self::assertStringContainsString('Groq', $content);
        self::assertStringContainsString('OpenAI', $content);
        self::assertStringContainsString('Steam Cloud', $content);
        self::assertStringNotContainsString('AI_API_KEY', $content);
    }
}
