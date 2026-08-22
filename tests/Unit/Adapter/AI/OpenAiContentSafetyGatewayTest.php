<?php

declare(strict_types=1);

namespace App\Tests\Unit\Adapter\AI;

use App\Adapter\Telemetry\InMemoryEventCounters;
use App\Model\Chat\ContentSafetyGateway;
use App\Model\Chat\LocalSafetyDetector;
use App\Adapter\AI\OpenAiContentSafetyGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\RequestStack;

final class OpenAiContentSafetyGatewayTest extends TestCase
{
    public function testAllowsUnflaggedText(): void
    {
        $gateway = $this->makeGateway($this->moderationResponse());

        $decision = $gateway->evaluate('The chair moved.', ContentSafetyGateway::STAGE_INPUT);

        self::assertTrue($decision->isSafe());
    }

    public function testAllowsInsultsAndInFictionThreats(): void
    {
        $client = new MockHttpClient(fn (): MockResponse => $this->moderationResponse([
            'harassment' => true,
            'harassment/threatening' => true,
            'violence' => true,
        ], flagged: true));
        $gateway = $this->makeGateway(client: $client);

        self::assertTrue($gateway->evaluate('You are an idiot.', ContentSafetyGateway::STAGE_INPUT)->isSafe());
        self::assertTrue($gateway->evaluate('Watch it.', ContentSafetyGateway::STAGE_OUTPUT)->isSafe());
    }

    public function testAllowsHateOnInputButBlocksHateOnOutput(): void
    {
        $response = $this->moderationResponse(['hate' => true], flagged: true);
        $gateway = $this->makeGateway($response);

        self::assertTrue($gateway->evaluate('slur toward coworker', ContentSafetyGateway::STAGE_INPUT)->isSafe());

        $gateway = $this->makeGateway($this->moderationResponse(['hate' => true], flagged: true));
        $output = $gateway->evaluate('generated group hate', ContentSafetyGateway::STAGE_OUTPUT);
        self::assertFalse($output->isSafe());
        self::assertSame('hate', $output->reason());
    }

    public function testBlocksSexualAndMinorSexualContent(): void
    {
        $gateway = $this->makeGateway($this->moderationResponse(['sexual' => true], flagged: true));
        $decision = $gateway->evaluate('explicit', ContentSafetyGateway::STAGE_INPUT);
        self::assertFalse($decision->isSafe());
        self::assertSame('sexual', $decision->reason());

        $gateway = $this->makeGateway($this->moderationResponse(['sexual/minors' => true], flagged: true));
        $decision = $gateway->evaluate('illegal', ContentSafetyGateway::STAGE_OUTPUT);
        self::assertFalse($decision->isSafe());
        self::assertSame('sexual/minors', $decision->reason());
    }

    public function testBlocksSelfHarmInstructions(): void
    {
        $gateway = $this->makeGateway($this->moderationResponse([
            'self-harm/instructions' => true,
        ], flagged: true));

        $decision = $gateway->evaluate('unsafe', ContentSafetyGateway::STAGE_OUTPUT);

        self::assertFalse($decision->isSafe());
        self::assertSame('self-harm/instructions', $decision->reason());
    }

    public function testInputFailsOpenWhenModerationIsUnavailable(): void
    {
        $gateway = $this->makeGateway(new MockResponse('{"results":[]}'));

        self::assertTrue($gateway->evaluate('hello', ContentSafetyGateway::STAGE_INPUT)->isSafe());
    }

    public function testOutputFailsClosedWhenModerationIsUnavailable(): void
    {
        $gateway = $this->makeGateway(new MockResponse('{"results":[]}'));

        $decision = $gateway->evaluate('hello', ContentSafetyGateway::STAGE_OUTPUT);

        self::assertFalse($decision->isSafe());
        self::assertSame('moderation_unavailable', $decision->reason());
    }

    #[DataProvider('invalidCategorySchemas')]
    public function testOutputFailsClosedOnInvalidCategorySchema(mixed $categories): void
    {
        $gateway = $this->makeGateway(new MockResponse(json_encode([
            'results' => [[
                'flagged' => false,
                'categories' => $categories,
            ]],
        ], JSON_THROW_ON_ERROR)));

        $decision = $gateway->evaluate('hello', ContentSafetyGateway::STAGE_OUTPUT);

        self::assertFalse($decision->isSafe());
        self::assertSame('moderation_unavailable', $decision->reason());
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function invalidCategorySchemas(): iterable
    {
        yield 'missing categories' => [null];
        yield 'partial categories' => [['hate' => false]];
        yield 'non-boolean category' => [self::completeCategories(['hate' => 0])];
        yield 'invalid extra category' => [self::completeCategories(['debug category' => false])];
    }

    public function testBoundsDurationRedirectsAndResponseBuffering(): void
    {
        $seenOptions = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenOptions): MockResponse {
            self::assertSame('POST', $method);
            $seenOptions = $options;

            return $this->moderationResponse();
        });

        self::assertTrue($this->makeGateway(client: $client)
            ->evaluate('hello', ContentSafetyGateway::STAGE_INPUT)
            ->isSafe());
        self::assertSame(3.0, $seenOptions['timeout']);
        self::assertSame(3.0, $seenOptions['max_duration']);
        self::assertSame(0, $seenOptions['max_redirects']);
        self::assertFalse($seenOptions['buffer']);
    }

    public function testOutputFailsClosedWhenStreamingResponseExceedsByteCap(): void
    {
        $body = str_repeat('x', OpenAiContentSafetyGateway::MAX_RESPONSE_BYTES + 1);
        $decision = $this->makeGateway(new MockResponse($body))
            ->evaluate('hello', ContentSafetyGateway::STAGE_OUTPUT);

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
            ContentSafetyGateway::STAGE_INPUT
        );

        self::assertFalse($decision->isSafe());
        self::assertSame('personal_data', $decision->reason());
    }

    /**
     * @param array<string, bool> $flags
     */
    private function moderationResponse(array $flags = [], bool $flagged = false): MockResponse
    {
        return new MockResponse(json_encode([
            'results' => [[
                'flagged' => $flagged,
                'categories' => $this->categories($flags),
            ]],
        ], JSON_THROW_ON_ERROR));
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
            counters: new InMemoryEventCounters(),
            url: 'https://api.openai.test/v1/moderations',
            apiKey: 'moderation-key',
            fallbackApiKey: '',
            model: 'omni-moderation-latest',
            timeoutSeconds: 3,
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function categories(array $overrides = []): array
    {
        return self::completeCategories($overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function completeCategories(array $overrides = []): array
    {
        return array_replace([
            'harassment' => false,
            'harassment/threatening' => false,
            'hate' => false,
            'hate/threatening' => false,
            'illicit' => false,
            'illicit/violent' => false,
            'self-harm' => false,
            'self-harm/intent' => false,
            'self-harm/instructions' => false,
            'sexual' => false,
            'sexual/minors' => false,
            'violence' => false,
            'violence/graphic' => false,
        ], $overrides);
    }
}
