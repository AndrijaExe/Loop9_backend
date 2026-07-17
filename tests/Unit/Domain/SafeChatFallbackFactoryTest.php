<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain;

use App\Domain\Chat\RuntimeContext;
use App\Domain\Chat\SafeChatFallbackFactory;
use App\Domain\Chat\Validation\AssistantReplyFormatValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SafeChatFallbackFactoryTest extends TestCase
{
    #[DataProvider('fallbackCases')]
    public function testFallbackAlwaysMatchesAssistantContract(string $language, string $reason): void
    {
        $message = (new SafeChatFallbackFactory())->create(
            RuntimeContext::fromArray(['language' => $language]),
            $reason
        );

        self::assertNotNull(
            (new AssistantReplyFormatValidator())->normalizeAndValidate($message->content())
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function fallbackCases(): iterable
    {
        yield 'English blocked' => ['en', 'sexual'];
        yield 'English unavailable' => ['en', 'moderation_unavailable'];
        yield 'Serbian blocked' => ['sr', 'hate'];
        yield 'Serbian unavailable' => ['Serbian', 'moderation_unavailable'];
    }
}
