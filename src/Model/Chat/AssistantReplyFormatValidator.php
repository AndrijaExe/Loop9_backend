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

        $jsonReply = $this->normalizeJsonReply($normalized);
        if ($jsonReply !== null) {
            return $jsonReply;
        }

        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        if (!is_string($normalized) || $normalized === '') {
            return null;
        }
        if (preg_match_all('/\[STATE\]/ui', $normalized) > 1) {
            return null;
        }

        if (preg_match(
            '/\A(?<reply>.+?)\s*\*{0,2}(?:\[STATE\]|STATE)\s*:?\s*\*{0,2}(?<state>.+?)\*{0,2}\z/ui',
            $normalized,
            $matches,
        ) === 1) {
            $kindness = $this->extractStateDelta($matches['state'], 'KINDNESS');
            $suspicion = $this->extractStateDelta($matches['state'], 'SUSPICION');
            if ($kindness !== null && $suspicion !== null) {
                return $this->canonicalReply($matches['reply'], $kindness, $suspicion);
            }

            // Never expose malformed internal metadata to the player. Keep the
            // usable reply and make the relationship delta neutral.
            return $this->canonicalReply($matches['reply'], 0, 0);
        }

        // Some providers occasionally omit the metadata trailer despite the
        // system prompt. Availability is preferable to a 500; local client-side
        // intent tracking still runs, while AI deltas remain safely neutral.
        if (stripos($normalized, 'KINDNESS') !== false || stripos($normalized, 'SUSPICION') !== false) {
            $metadataOffset = $this->firstMetadataOffset($normalized);
            if ($metadataOffset !== null) {
                return $this->canonicalReply(substr($normalized, 0, $metadataOffset), 0, 0);
            }
        }

        return $this->canonicalReply($normalized, 0, 0);
    }

    private function normalizeJsonReply(string $content): ?string
    {
        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $data = array_change_key_case($decoded, CASE_LOWER);
        $reply = null;
        foreach (['reply_text', 'reply', 'message', 'content'] as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                $reply = $data[$key];
                break;
            }
        }
        if ($reply === null) {
            return null;
        }

        $state = isset($data['state']) && is_array($data['state'])
            ? array_change_key_case($data['state'], CASE_LOWER)
            : [];
        $kindness = $this->normalizeDelta($data['kindness'] ?? $state['kindness'] ?? null) ?? 0;
        $suspicion = $this->normalizeDelta($data['suspicion'] ?? $state['suspicion'] ?? null) ?? 0;

        return $this->canonicalReply($reply, $kindness, $suspicion);
    }

    private function extractStateDelta(string $state, string $label): ?int
    {
        if (preg_match(
            '/(?:\A|[\s,;|])' . preg_quote($label, '/') . '\s*[:=]\s*(-1|0|1)(?=\s*(?:[,;|]|\z))/ui',
            $state,
            $matches,
        ) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function normalizeDelta(mixed $value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/\A-?[0-9]\z/', $value) === 1)) {
            return null;
        }

        $delta = (int) $value;

        return in_array($delta, [-1, 0, 1], true) ? $delta : null;
    }

    private function firstMetadataOffset(string $content): ?int
    {
        $offsets = [];
        foreach (['[STATE]', 'STATE:', 'KINDNESS', 'SUSPICION'] as $marker) {
            $offset = stripos($content, $marker);
            if ($offset !== false) {
                $offsets[] = $offset;
            }
        }

        return $offsets === [] ? null : min($offsets);
    }

    private function canonicalReply(string $reply, int $kindness, int $suspicion): ?string
    {
        $reply = preg_replace('/<\/?reply_text>/ui', '', $reply);
        $reply = preg_replace('/\s+/u', ' ', is_string($reply) ? $reply : '');
        $reply = trim(is_string($reply) ? $reply : '', " \t\n\r\0\x0B\"'");
        if ($reply === '' || stripos($reply, '[STATE]') !== false || str_contains($reply, '```')) {
            return null;
        }

        return sprintf(
            '%s[STATE]KINDNESS=%d;SUSPICION=%d',
            $reply,
            $kindness,
            $suspicion,
        );
    }
}
