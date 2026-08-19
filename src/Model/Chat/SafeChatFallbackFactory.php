<?php

declare(strict_types=1);

namespace App\Model\Chat;

final class SafeChatFallbackFactory
{
    public function create(RuntimeContext $context, string $reason): Message
    {
        $language = strtolower((string) $context->language());
        $unavailable = $reason === 'moderation_unavailable';

        $reply = match (true) {
            str_starts_with($language, 'sr'), str_contains($language, 'serb') => $unavailable
                ? 'Veza pucketa; ne čujem te dobro. Gledaj sprat i reci mi šta se promenilo.'
                : 'Pusti to sada—gledaj sprat i reci mi šta se promenilo.',
            str_starts_with($language, 'de'), str_contains($language, 'german') => $unavailable
                ? 'Die Leitung bricht ab; ich höre dich nicht richtig. Beobachte den Flur und sag mir, was sich verändert hat.'
                : 'Lass das jetzt—beobachte den Flur und sag mir, was sich verändert hat.',
            str_starts_with($language, 'fr'), str_contains($language, 'french') => $unavailable
                ? 'La ligne coupe; je ne t’entends pas bien. Observe l’étage et dis-moi ce qui a changé.'
                : 'Laisse ça—observe l’étage et dis-moi ce qui a changé.',
            str_starts_with($language, 'ru'), str_contains($language, 'russian') => $unavailable
                ? 'Связь прерывается; я плохо тебя слышу. Осмотри этаж и скажи, что изменилось.'
                : 'Оставь это—осмотри этаж и скажи, что изменилось.',
            default => $unavailable
                ? 'The line is breaking up... I cannot hear you clearly; watch the floor and tell me what changed.'
                : 'Leave that alone—watch the floor and tell me what changed.',
        };

        return new Message('assistant', $reply . '[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=0');
    }
}
