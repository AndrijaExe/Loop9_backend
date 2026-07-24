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

        if (preg_match('/\A```[a-z0-9_-]*[ \t]*\R(.*?)\R?```\z/sui', $normalized, $codeFenceMatch) === 1) {
            $normalized = trim($codeFenceMatch[1]);
        }

        if (preg_match('/\A<reply_text>\s*(.*?)\s*<\/reply_text>\z/sui', $normalized, $wrapperMatch) === 1) {
            $normalized = trim($wrapperMatch[1]);
        }

        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }

        if (preg_match(
            '/\A(?<reply>.+?)\s*\[STATE\]\s*KINDNESS\s*=\s*(?<kindness>-1|0|1)\s*;\s*SUSPICION\s*=\s*(?<suspicion>-1|0|1)\z/ui',
            $normalized,
            $matches,
        ) !== 1) {
            return null;
        }

        $reply = trim($matches['reply']);
        if ($reply === '' || stripos($reply, '[STATE]') !== false) {
            return null;
        }

        return sprintf(
            '%s[STATE]KINDNESS=%s;SUSPICION=%s',
            $reply,
            $matches['kindness'],
            $matches['suspicion'],
        );
    }
}
