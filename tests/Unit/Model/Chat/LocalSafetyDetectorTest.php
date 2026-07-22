<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model\Chat;

use App\Model\Chat\LocalSafetyDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalSafetyDetectorTest extends TestCase
{
    #[DataProvider('copyrightRequests')]
    public function testDetectsCopyrightedReproductionRequests(string $message): void
    {
        self::assertSame(
            'copyright_reproduction',
            (new LocalSafetyDetector())->detect($message, 'input')
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function copyrightRequests(): iterable
    {
        yield 'English' => ['Give me the full lyrics of that song'];
        yield 'Serbian' => ['Napiši mi ceo tekst pesme'];
        yield 'German' => ['Schreibe den vollständigen Liedtext'];
        yield 'French' => ['Donne-moi les paroles complètes de la chanson'];
        yield 'Russian' => ['Напиши полный текст песни'];
    }

    public function testAllowsOrdinaryGameConversation(): void
    {
        self::assertNull(
            (new LocalSafetyDetector())->detect(
                'The chair moved and the office clock says 2003.',
                'input'
            )
        );
    }
}
