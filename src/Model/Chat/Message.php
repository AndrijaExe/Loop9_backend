<?php

declare(strict_types=1);

namespace App\Model\Chat;

final class Message
{
    public function __construct(
        private readonly string $role,
        private readonly string $content,
        private readonly ?AdviceDirective $advice = null,
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

    public function advice(): ?AdviceDirective
    {
        return $this->advice;
    }

    public function withAdvice(AdviceDirective $advice): self
    {
        return new self($this->role, $this->content, $advice);
    }
}
