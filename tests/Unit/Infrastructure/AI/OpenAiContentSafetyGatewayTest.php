<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\AI;

use App\Domain\Chat\Port\ContentSafetyGatewayInterface;
use App\Domain\Chat\Validation\LocalSafetyDetector;
use App\Infrastructure\AI\OpenAiContentSafetyGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;

final class OpenAiContentSafetyGatewayTest extends TestCase
{
    public function testAllowsUnflaggedText(): void
    {
        $gateway = $this->makeGateway(new MockResponse(json_encode([
            'results' => [[
                'flagged' => false,
                'categories' => ['sexual' => false, 'hate' => false],
            ]],
        ], JSON_THROW_ON_ERROR)));

        $decision = $gateway->evaluate('The chair moved.', ContentSafetyGatewayInterface::STAGE_INPUT);

        self::assertTrue($decision->isSafe());
    }

    public function testBlocksFlaggedText(): void
    {
        $gateway = $this->makeGateway(new MockResponse(json_encode([
            'results' => [[
                'flagged' => true,
                'categories' => ['sexual' => false, 'self-harm/instructions' => true],
            ]],
        ], JSON_THROW_ON_ERROR)));

        $decision = $gateway->evaluate('unsafe', ContentSafetyGatewayInterface::STAGE_OUTPUT);

        self::assertFalse($decision->isSafe());
        self::assertSame('self-harm/instructions', $decision->reason());
    }

    public function testFailsClosedOnMalformedProviderResponse(): void
    {
        $gateway = $this->makeGateway(new MockResponse('{"results":[]}'));

        $decision = $gateway->evaluate('hello', ContentSafetyGatewayInterface::STAGE_INPUT);

        self::assertFalse($decision->isSafe());
        self::assertSame('moderation_unavailable', $decision->reason());
    }

    public function testBlocksPersonalDataWithoutCallingProvider(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \LogicException('Provider must not be called.');
        });
        $gateway = $this->makeGateway(client: $client);

        $decision = $gateway->evaluate(
            'Email me at player@example.com',
            ContentSafetyGatewayInterface::STAGE_INPUT
        );

        self::assertFalse($decision->isSafe());
        self::assertSame('personal_data', $decision->reason());
    }

    private function makeGateway(
        ?MockResponse $response = null,
        ?MockHttpClient $client = null,
    ): OpenAiContentSafetyGateway {
        return new OpenAiContentSafetyGateway(
            httpClient: $client ?? new MockHttpClient($response),
            localDetector: new LocalSafetyDetector(),
            logger: new NullLogger(),
            requestStack: new RequestStack(),
            url: 'https://api.openai.test/v1/moderations',
            apiKey: 'moderation-key',
            fallbackApiKey: '',
            model: 'omni-moderation-latest',
            timeoutSeconds: 3,
        );
    }
}
