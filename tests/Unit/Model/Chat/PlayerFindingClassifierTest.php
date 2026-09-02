<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model\Chat;

use App\Model\Chat\PlayerFindingClassifier;
use App\Model\Chat\RuntimeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlayerFindingClassifierTest extends TestCase
{
    #[DataProvider('findingProvider')]
    public function testRecognizesReportedFindings(string $message): void
    {
        self::assertTrue(
            (new PlayerFindingClassifier())->reportedFinding($message, RuntimeContext::fromArray([])),
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function findingProvider(): iterable
    {
        yield 'English anomaly' => ['The chair moved.'];
        yield 'Serbian clean report' => ['Sve je u redu.'];
        yield 'German clean report' => ['Alles ist in Ordnung.'];
        yield 'Russian anomaly' => ['Я слышу странный звук.'];
    }

    public function testDoesNotTreatDecisionRequestAsFinding(): void
    {
        self::assertFalse(
            (new PlayerFindingClassifier())->reportedFinding(
                'Which elevator should I take?',
                RuntimeContext::fromArray([]),
            ),
        );
    }

    public function testOfftopicContextAlwaysWins(): void
    {
        self::assertFalse(
            (new PlayerFindingClassifier())->reportedFinding(
                'The chair moved.',
                RuntimeContext::fromArray(['offtopic' => true]),
            ),
        );
    }
}
