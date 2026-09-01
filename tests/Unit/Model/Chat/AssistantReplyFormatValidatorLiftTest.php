<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model\Chat;

use App\Model\Chat\AssistantReplyFormatValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AssistantReplyFormatValidatorLiftTest extends TestCase
{
    private AssistantReplyFormatValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new AssistantReplyFormatValidator();
    }

    #[DataProvider('expectedLiftProvider')]
    public function testContainsExpectedLiftAdvice(string $content, string $lift, bool $expected): void
    {
        self::assertSame($expected, $this->validator->containsExpectedLiftAdvice($content, $lift));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function expectedLiftProvider(): iterable
    {
        yield 'en_dark' => ['Take the dark elevator now.[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=0', 'dark', true];
        yield 'en_lit_mismatch' => ['Take the lit elevator now.[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=0', 'dark', false];
        yield 'sr_dark' => ['Idi mračnim liftom.[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=0', 'dark', true];
        yield 'de_lit' => ['Nimm den beleuchteten Aufzug.[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=0', 'lit', true];
        yield 'fr_dark' => ['Prends l’ascenseur sombre.[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=0', 'dark', true];
        yield 'ru_lit' => ['Бери освещённый лифт.[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=0', 'lit', true];
    }
}
