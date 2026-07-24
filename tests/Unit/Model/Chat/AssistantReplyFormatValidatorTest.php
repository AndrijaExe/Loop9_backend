<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model\Chat;

use App\Model\Chat\AssistantReplyFormatValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantReplyFormatValidatorTest extends TestCase
{
    private AssistantReplyFormatValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AssistantReplyFormatValidator();
    }

    public function testAcceptsValidOneLineReply(): void
    {
        $input = 'Take the dark elevator.[STATE]KINDNESS=1;SUSPICION=0';

        self::assertSame($input, $this->validator->normalizeAndValidate($input));
    }

    public function testStripsReplyTextWrappers(): void
    {
        $input = '<reply_text>Go lit.[STATE]KINDNESS=-1;SUSPICION=1</reply_text>';

        self::assertSame(
            'Go lit.[STATE]KINDNESS=-1;SUSPICION=1',
            $this->validator->normalizeAndValidate($input)
        );
    }

    public function testCanonicalizesCommonModelFormattingDrift(): void
    {
        $input = "```text\nAnswer from Dragojlo.\n[STATE] KINDNESS = 0; SUSPICION = -1\n```";

        self::assertSame(
            'Answer from Dragojlo.[STATE]KINDNESS=0;SUSPICION=-1',
            $this->validator->normalizeAndValidate($input),
        );
    }

    public function testCanonicalizesAlternateStateLabels(): void
    {
        self::assertSame(
            'Keep moving.[STATE]KINDNESS=1;SUSPICION=-1',
            $this->validator->normalizeAndValidate(
                'Keep moving. **STATE:** SUSPICION: -1 | KINDNESS: 1'
            ),
        );
    }

    public function testCanonicalizesJsonReply(): void
    {
        self::assertSame(
            'Use the lit elevator.[STATE]KINDNESS=-1;SUSPICION=1',
            $this->validator->normalizeAndValidate(json_encode([
                'reply_text' => 'Use the lit elevator.',
                'state' => ['kindness' => -1, 'suspicion' => 1],
            ], JSON_THROW_ON_ERROR)),
        );
    }

    public function testUsesNeutralStateWhenTrailerIsMissing(): void
    {
        self::assertSame(
            'Just text[STATE]KINDNESS=0;SUSPICION=0',
            $this->validator->normalizeAndValidate('Just text'),
        );
    }

    public function testUsesNeutralStateInsteadOfExposingMalformedMetadata(): void
    {
        self::assertSame(
            'Hi[STATE]KINDNESS=0;SUSPICION=0',
            $this->validator->normalizeAndValidate('Hi[STATE]KINDNESS=2;SUSPICION=0'),
        );
    }

    #[DataProvider('invalidReplies')]
    public function testRejectsInvalidReplies(string $input): void
    {
        self::assertNull($this->validator->normalizeAndValidate($input));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidReplies(): iterable
    {
        yield 'empty' => [''];
        yield 'duplicate state marker' => ['Hi [STATE] again[STATE]KINDNESS=0;SUSPICION=0'];
        yield 'lowercase duplicate marker' => ['Hi [state] again[STATE]KINDNESS=0;SUSPICION=0'];
        yield 'malformed inline code fence' => ['```textual reply[STATE]KINDNESS=0;SUSPICION=0```'];
    }
}
