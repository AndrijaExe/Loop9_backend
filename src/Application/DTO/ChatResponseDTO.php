<?php

declare(strict_types=1);

namespace App\Application\DTO;

final class ChatResponseDTO
{
    public function __construct(
        private readonly string $role,
        private readonly string $message,
        private readonly \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @return array{role: string, message: string, createdAt: string}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'message' => $this->message,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
        ];
    }
}
