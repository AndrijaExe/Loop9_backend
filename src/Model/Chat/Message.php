<?php

declare(strict_types=1);

namespace App\Model\Chat;

final class Message
{
    public function __construct(
        private readonly string $role,
        private readonly string $content,
    ) {
    }

    public function role(): string
    {
        return $this->role;
    }

    public function content(): string
    {
        return $this->content;
    }
}
