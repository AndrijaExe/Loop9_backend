<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model\Chat;

use App\Model\Chat\RuntimeContext;
use App\Model\Chat\SafeChatFallbackFactory;
use App\Model\Chat\AssistantReplyFormatValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeChatFallbackFactoryTest extends TestCase
{
    #[DataProvider('fallbackCases')]
    public function testFallbackAlwaysMatchesAssistantContract(
        string $language,
        string $reason,
        string $expectedFragment,
    ): void
    {
        $message = (new SafeChatFallbackFactory())->create(
            RuntimeContext::fromArray(['language' => $language]),
            $reason
        );

        self::assertNotNull(
            (new AssistantReplyFormatValidator())->normalizeAndValidate($message->content())
        );
        self::assertStringContainsString($expectedFragment, $message->content());
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function fallbackCases(): iterable
    {
        yield 'English blocked' => ['en', 'sexual', 'Leave that alone'];
        yield 'English unavailable' => ['en', 'moderation_unavailable', 'line is breaking up'];
        yield 'Serbian blocked' => ['sr', 'hate', 'Pusti to'];
        yield 'Serbian unavailable' => ['Serbian', 'moderation_unavailable', 'Veza pucketa'];
        yield 'German blocked' => ['de', 'hate', 'Lass das'];
        yield 'French unavailable' => ['fr', 'moderation_unavailable', 'La ligne coupe'];
        yield 'Russian blocked' => ['ru', 'hate', 'Оставь это'];
    }
}
