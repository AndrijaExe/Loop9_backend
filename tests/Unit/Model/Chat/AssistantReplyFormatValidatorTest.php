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
        yield 'multiline' => ["Hello\n[STATE]KINDNESS=0;SUSPICION=0"];
        yield 'missing state' => ['Just text'];
        yield 'spaces around equals' => ['Hi[STATE]KINDNESS = 0;SUSPICION = 0'];
        yield 'invalid delta' => ['Hi[STATE]KINDNESS=2;SUSPICION=0'];
    }
}
