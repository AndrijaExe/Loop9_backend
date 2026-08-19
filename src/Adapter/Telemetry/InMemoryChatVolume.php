<?php

declare(strict_types=1);

namespace App\Adapter\Telemetry;

use App\Model\Telemetry\ChatVolume;

/**
 * Volume for the length of one process, which is all a machine without Redis can offer.
 */
final class InMemoryChatVolume implements ChatVolume
{
    private string $day = '';

    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(
        private readonly int $watchAfter = 40,
    ) {
    }

    public function recorded(string $playerId): int
    {
        $this->roll();
        $mark = substr(hash('sha256', $playerId), 0, 32);
        $this->counts[$mark] = ($this->counts[$mark] ?? 0) + 1;

        return $this->counts[$mark];
    }

    public function snapshot(): array
    {
        $this->roll();
        $heaviest = $this->counts === [] ? 0 : max($this->counts);
        $hot = 0;
        if ($this->watchAfter > 0) {
            foreach ($this->counts as $count) {
                if ($count >= $this->watchAfter) {
                    ++$hot;
                }
            }
        }

        return ['heaviest' => $heaviest, 'hot' => $hot];
    }

    private function roll(): void
    {
        $today = gmdate('Ymd');
        if ($this->day === $today) {
            return;
        }

        $this->day = $today;
        $this->counts = [];
    }
}
