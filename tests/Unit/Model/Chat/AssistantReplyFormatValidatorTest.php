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
        $input = 'Take the dark elevator.[STATE]KINDNESS=1;SUSPICION=0;DEPENDENCY=0';

        self::assertSame($input, $this->validator->normalizeAndValidate($input));
    }

    public function testStripsReplyTextWrappers(): void
    {
        $input = '<reply_text>Go lit.[STATE]KINDNESS=-1;SUSPICION=1</reply_text>';

        self::assertSame(
            'Go lit.[STATE]KINDNESS=-1;SUSPICION=1;DEPENDENCY=0',
            $this->validator->normalizeAndValidate($input)
        );
    }

    public function testCanonicalizesCommonModelFormattingDrift(): void
    {
        $input = "```text\nAnswer from Dragojlo.\n[STATE] KINDNESS = 0; SUSPICION = -1\n```";

        self::assertSame(
            'Answer from Dragojlo.[STATE]KINDNESS=0;SUSPICION=-1;DEPENDENCY=0',
            $this->validator->normalizeAndValidate($input),
        );
    }

    public function testCanonicalizesAlternateStateLabels(): void
    {
        self::assertSame(
            'Keep moving.[STATE]KINDNESS=1;SUSPICION=-1;DEPENDENCY=0',
            $this->validator->normalizeAndValidate(
                'Keep moving. **STATE:** SUSPICION: -1 | KINDNESS: 1'
            ),
        );
    }

    public function testCanonicalizesJsonReply(): void
    {
        self::assertSame(
            'Use the lit elevator.[STATE]KINDNESS=-1;SUSPICION=1;DEPENDENCY=0',
            $this->validator->normalizeAndValidate(json_encode([
                'reply_text' => 'Use the lit elevator.',
                'state' => ['kindness' => -1, 'suspicion' => 1],
            ], JSON_THROW_ON_ERROR)),
        );
    }

    public function testPreservesDependencyDelta(): void
    {
        self::assertSame(
            'You choose.[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=1',
            $this->validator->normalizeAndValidate(
                'You choose.[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=1'
            ),
        );
    }

    public function testTreatsAnAbsentDependencyAxisAsNeutral(): void
    {
        self::assertSame(
            'Take the dark elevator.[STATE]KINDNESS=1;SUSPICION=0;DEPENDENCY=0',
            $this->validator->normalizeAndValidate(
                'Take the dark elevator.[STATE]KINDNESS=1;SUSPICION=0'
            ),
        );
    }

    #[DataProvider('localizedElevatorNames')]
    public function testDetectsLocalizedElevatorNamesInWithheldReplies(string $reply): void
    {
        self::assertTrue($this->validator->containsLocalizedElevatorName($reply));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function localizedElevatorNames(): iterable
    {
        yield 'English lit' => ['Take the lit elevator.'];
        yield 'English dark' => ['Use the dark elevator.'];
        yield 'Serbian inflected lit' => ['Idi ka osvetljenom liftu.'];
        yield 'Serbian inflected dark' => ['Uđi u mračni lift.'];
        yield 'German lit' => ['Nimm den beleuchteten Aufzug.'];
        yield 'German dark' => ['Geh zum dunklen Aufzug.'];
        yield 'French lit' => ['Prends l’ascenseur éclairé.'];
        yield 'French dark' => ['Va vers l’ascenseur sombre.'];
        yield 'Russian lit' => ['Иди к освещённому лифту.'];
        yield 'Russian dark' => ['Выбирай тёмный лифт.'];
    }

    public function testDoesNotTreatOrdinarySearchGuidanceAsAnElevatorVerdict(): void
    {
        self::assertFalse($this->validator->containsLocalizedElevatorName(
            'Check the dark archive room and tell me what moved.'
        ));
    }

    /**
     * A silent fall back to 0 would drop every dependency diagnosis the moment a
     * model drifted, and the bonded endings would never fire.
     */
    #[DataProvider('malformedDependencyValues')]
    public function testRejectsAMalformedDependencyInsteadOfReadingItAsNeutral(string $value): void
    {
        self::assertNull(
            $this->validator->normalizeAndValidate(
                "Take the dark elevator.[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY={$value}"
            )
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedDependencyValues(): iterable
    {
        yield 'out of range' => ['2'];
        yield 'negative out of range' => ['-5'];
        yield 'decimal' => ['1.0'];
        yield 'signed' => ['+1'];
        yield 'not a number' => ['high'];
        yield 'empty' => [''];
    }

    public function testUsesNeutralStateWhenTrailerIsMissing(): void
    {
        self::assertSame(
            'Just text[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=0',
            $this->validator->normalizeAndValidate('Just text'),
        );
    }

    public function testRejectsMalformedMetadata(): void
    {
        self::assertNull(
            $this->validator->normalizeAndValidate('Hi[STATE]KINDNESS=2;SUSPICION=0')
        );
    }

    public function testAcceptsReplyAtUtf8CharacterLimit(): void
    {
        $reply = str_repeat('ž', AssistantReplyFormatValidator::MAX_REPLY_CHARACTERS);

        self::assertSame(
            $reply . '[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=0',
            $this->validator->normalizeAndValidate($reply),
        );
    }

    public function testRejectsOverlongUnicodeReply(): void
    {
        $reply = str_repeat('ž', AssistantReplyFormatValidator::MAX_REPLY_CHARACTERS + 1);

        self::assertNull($this->validator->normalizeAndValidate(
            $reply . '[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=0'
        ));
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
        yield 'unrecognized JSON object' => ['{"status":"ok","trace":"internal"}'];
        yield 'valid JSON scalar' => ['"debug output"'];
        yield 'recognized JSON with debug field' => ['{"reply":"Hello.","debug":"hidden"}'];
        yield 'recognized JSON with invalid delta' => ['{"reply":"Hello.","kindness":2}'];
        yield 'debug artifact' => ['DEBUG: generated reply'];
        yield 'analysis artifact with state' => ['Analysis: hidden reasoning[STATE]KINDNESS=0;SUSPICION=0'];
        yield 'XML artifact' => ['<tool_call>lookup</tool_call>'];
        yield 'markdown list recovery' => ['- First internal option'];
        yield 'multiline recovery' => ["First line\nSecond line"];
        yield 'three sentences' => ['One. Two. Three.'];
    }
}
