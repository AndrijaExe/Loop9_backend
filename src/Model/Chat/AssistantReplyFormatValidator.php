<?php

declare(strict_types=1);

namespace App\Model\Chat;

/**
 * Validates the mandatory one-line reply format expected by the Unreal client:
 * {reply_text}[STATE]KINDNESS=-1|0|1;SUSPICION=-1|0|1
 */
final class AssistantReplyFormatValidator
{
    public function normalizeAndValidate(string $content): ?string
    {
        $normalized = trim($content);

        if (str_starts_with($normalized, '<reply_text>') && str_ends_with($normalized, '</reply_text>')) {
            $normalized = substr($normalized, strlen('<reply_text>'), -strlen('</reply_text>'));
            $normalized = trim($normalized);
        }

        if ($normalized === '' || str_contains($normalized, "\n") || str_contains($normalized, "\r")) {
            return null;
        }

        if (!preg_match('/^.+\[STATE\]KINDNESS=(-1|0|1);SUSPICION=(-1|0|1)$/u', $normalized)) {
            return null;
        }

        return $normalized;
    }
}
