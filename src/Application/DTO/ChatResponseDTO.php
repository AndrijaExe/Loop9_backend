<?php

declare(strict_types=1);

namespace App\Application\DTO;

use App\Model\Chat\AdviceDirective;

final class ChatResponseDTO
{
    public function __construct(
        private readonly string $role,
        private readonly string $message,
        private readonly \DateTimeImmutable $createdAt,
        private readonly ?AdviceDirective $advice = null,
    ) {
    }

    /**
     * @return array{role: string, message: string, createdAt: string, advice?: array{mode: string, lift: string, suggested_zone?: string, commitment_id: string}}
     */
    public function toArray(): array
    {
        $out = [
            'role' => $this->role,
            'message' => $this->message,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
        ];

        if ($this->advice !== null) {
            $out['advice'] = $this->advice->toClientArray();
        }

        return $out;
    }
}
