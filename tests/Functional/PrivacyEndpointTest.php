<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PrivacyEndpointTest extends WebTestCase
{
    public function testPrivacyPolicyIsPublicAndDescribesAiProcessing(): void
    {
        $client = static::createClient();
        $client->request('GET', '/privacy');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');

        $content = $client->getResponse()->getContent() ?: '';
        self::assertStringContainsString('Loop 9 Privacy Policy', $content);
        self::assertStringContainsString('Groq', $content);
        self::assertStringContainsString('OpenAI', $content);
        self::assertStringContainsString('Steam Cloud', $content);
        self::assertStringNotContainsString('AI_API_KEY', $content);
    }
}
