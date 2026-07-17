<?php

declare(strict_types=1);

namespace App\Domain\Chat;

final class SafeChatFallbackFactory
{
    public function create(RuntimeContext $context, string $reason): Message
    {
        $language = strtolower((string) $context->language());
        $isSerbian = str_starts_with($language, 'sr') || str_contains($language, 'serb');

        if ($isSerbian) {
            $reply = $reason === 'moderation_unavailable'
                ? 'Veza pucketa... ne čujem te dobro. Gledaj sprat i reci mi šta se promenilo.'
                : 'Pusti to sada—gledaj sprat i reci mi šta se promenilo.';
        } else {
            $reply = $reason === 'moderation_unavailable'
                ? 'The line is breaking up... I cannot hear you clearly. Watch the floor and tell me what changed.'
                : 'Leave that alone—watch the floor and tell me what changed.';
        }

        return new Message('assistant', $reply . '[STATE]KINDNESS=0;SUSPICION=0');
    }
}
