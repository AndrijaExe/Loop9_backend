<?php

declare(strict_types=1);

namespace App\Model\Chat;

final readonly class AdvicePlanner
{
    public function __construct(
        private PlayerFindingClassifier $findingClassifier,
        private AdvicePolicy $advicePolicy,
    ) {
    }

    public function plan(string $playerMessage, RuntimeContext $context): AdviceDirective
    {
        return $this->advicePolicy->decide(
            $context,
            $this->findingClassifier->reportedFinding($playerMessage, $context),
        );
    }
}
