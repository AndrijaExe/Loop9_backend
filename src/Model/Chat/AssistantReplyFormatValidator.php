<?php

declare(strict_types=1);

namespace App\Model\Chat;

/**
 * Validates the mandatory one-line reply format expected by the Unreal client:
 * {reply_text}[STATE]KINDNESS=-1|0|1;SUSPICION=-1|0|1;DEPENDENCY=-1|0|1
 */
final class AssistantReplyFormatValidator
{
    public const MAX_REPLY_CHARACTERS = 280;

    public function normalizeAndValidate(string $content): ?string
    {
        $normalized = trim($content);

        if (preg_match('/\A```[a-z0-9_-]*[ \t]*\R(.*?)\R?```\z/sui', $normalized, $codeFenceMatch) === 1) {
            $normalized = trim($codeFenceMatch[1]);
        }

        if (preg_match('/\A<reply_text>\s*(.*?)\s*<\/reply_text>\z/sui', $normalized, $wrapperMatch) === 1) {
            $normalized = trim($wrapperMatch[1]);
        }

        if ($this->isValidJson($normalized)) {
            return $this->normalizeJsonReply($normalized);
        }

        $plainTextHadLineBreak = preg_match('/[\r\n]/u', $normalized) === 1;
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
            $dependency = $this->extractOptionalStateDelta($matches['state'], 'DEPENDENCY');
            if ($kindness !== null && $suspicion !== null && $dependency !== null) {
                return $this->canonicalReply($matches['reply'], $kindness, $suspicion, $dependency);
            }

            return null;
        }

        if ($plainTextHadLineBreak || !$this->isSafePlainTextRecovery($normalized)) {
            return null;
        }

        return $this->canonicalReply($normalized, 0, 0, 0);
    }

    /**
     * A withheld reply must not leak the gameplay verdict even when the model
     * ignored the prompt. Match the exact localized elevator vocabulary taught
     * by the game, including ordinary grammatical inflections.
     */
    public function containsLocalizedElevatorName(string $content): bool
    {
        $normalized = mb_strtolower($content);
        $normalized = strtr($normalized, [
            'č' => 'c',
            'ć' => 'c',
            'š' => 's',
            'đ' => 'dj',
            'ž' => 'z',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ё' => 'е',
        ]);

        return preg_match(
            '/(?:'
            . '\b(?:lit|dark)\s+elevators?\b|'
            . '\b(?:osvetljen\p{L}*|mracn\p{L}*)\s+lift\p{L}*\b|'
            . '\b(?:beleuchtet\p{L}*|dunkl\p{L}*)\s+aufzug\p{L}*\b|'
            . '\bascenseur\p{L}*\s+(?:eclair\p{L}*|sombre\p{L}*)\b|'
            . '(?:освещ\p{L}*|темн\p{L}*)\s+лифт\p{L}*'
            . ')/u',
            $normalized,
        ) === 1;
    }

    /**
     * Forced wrong-lift / accurate-lift replies must include the expected side.
     * Matching is language-agnostic across the taught elevator vocabulary.
     */
    public function containsExpectedLiftAdvice(string $content, string $lift): bool
    {
        $normalized = mb_strtolower($content);
        $normalized = strtr($normalized, [
            'č' => 'c',
            'ć' => 'c',
            'š' => 's',
            'đ' => 'dj',
            'ž' => 'z',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ё' => 'е',
        ]);

        $pattern = match (strtolower($lift)) {
            'lit' => '/(?:'
                . '\blit\s+elevators?\b|'
                . '\bosvetljen\p{L}*\s+lift\p{L}*\b|'
                . '\bbeleuchtet\p{L}*\s+aufzug\p{L}*\b|'
                . '\bascenseur\p{L}*\s+eclair\p{L}*\b|'
                . 'освещ\p{L}*\s+лифт\p{L}*'
                . ')/u',
            'dark' => '/(?:'
                . '\bdark\s+elevators?\b|'
                . '\bmracn\p{L}*\s+lift\p{L}*\b|'
                . '\bdunkl\p{L}*\s+aufzug\p{L}*\b|'
                . '\bascenseur\p{L}*\s+sombre\p{L}*\b|'
                . 'темн\p{L}*\s+лифт\p{L}*'
                . ')/u',
            default => null,
        };

        return $pattern !== null && preg_match($pattern, $normalized) === 1;
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
        if (count($data) !== count($decoded)) {
            return null;
        }
        $allowedKeys = ['reply_text', 'reply', 'message', 'content', 'state', 'kindness', 'suspicion', 'dependency'];
        if (array_diff(array_keys($data), $allowedKeys) !== []) {
            return null;
        }

        $replyKeys = array_values(array_filter(
            ['reply_text', 'reply', 'message', 'content'],
            static fn (string $key): bool => array_key_exists($key, $data),
        ));
        if (count($replyKeys) !== 1
            || !is_string($data[$replyKeys[0]])
            || trim($data[$replyKeys[0]]) === '') {
            return null;
        }
        $reply = $data[$replyKeys[0]];

        $state = isset($data['state']) && is_array($data['state'])
            ? array_change_key_case($data['state'], CASE_LOWER)
            : [];
        if (isset($data['state'])
            && (!is_array($data['state'])
                || count($state) !== count($data['state'])
                || array_diff(array_keys($state), ['kindness', 'suspicion', 'dependency']) !== [])) {
            return null;
        }

        $kindnessRaw = $data['kindness'] ?? $state['kindness'] ?? 0;
        $suspicionRaw = $data['suspicion'] ?? $state['suspicion'] ?? 0;
        $dependencyRaw = $data['dependency'] ?? $state['dependency'] ?? 0;
        $kindness = $this->normalizeDelta($kindnessRaw);
        $suspicion = $this->normalizeDelta($suspicionRaw);
        $dependency = $this->normalizeDelta($dependencyRaw);
        if ($kindness === null || $suspicion === null || $dependency === null) {
            return null;
        }

        return $this->canonicalReply($reply, $kindness, $suspicion, $dependency);
    }

    private function isValidJson(string $content): bool
    {
        if ($content === '') {
            return false;
        }

        try {
            json_decode($content, true, 16, JSON_THROW_ON_ERROR);

            return true;
        } catch (\JsonException) {
            return false;
        }
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

    /**
     * A missing axis is tolerated while models roll out the third value, but an
     * axis the model did commit to must parse — otherwise drift like
     * DEPENDENCY=1.0 would be silently read as 0.
     */
    private function extractOptionalStateDelta(string $state, string $label): ?int
    {
        if (preg_match(
            '/(?:\A|[\s,;|])' . preg_quote($label, '/') . '\s*[:=]/ui',
            $state,
        ) !== 1) {
            return 0;
        }

        return $this->extractStateDelta($state, $label);
    }

    private function normalizeDelta(mixed $value): ?int
    {
        if (!is_int($value) && !(is_string($value) && preg_match('/\A-?[0-9]\z/', $value) === 1)) {
            return null;
        }

        $delta = (int) $value;

        return in_array($delta, [-1, 0, 1], true) ? $delta : null;
    }

    private function isSafePlainTextRecovery(string $content): bool
    {
        if (!$this->isSafePlayerReply($content)
            || preg_match('/[\r\n]/u', $content) === 1
            || preg_match('/[{}\[\]<>`]/u', $content) === 1
            || preg_match('/\b(?:STATE|KINDNESS|SUSPICION|DEPENDENCY)\b/ui', $content) === 1
            || preg_match('/\A\s*(?:[-*#>]|\d+[.)])\s+/u', $content) === 1) {
            return false;
        }

        return true;
    }

    private function canonicalReply(string $reply, int $kindness, int $suspicion, int $dependency = 0): ?string
    {
        $reply = preg_replace('/<\/?reply_text>/ui', '', $reply);
        $reply = preg_replace('/\s+/u', ' ', is_string($reply) ? $reply : '');
        $reply = trim(is_string($reply) ? $reply : '', " \t\n\r\0\x0B\"'");
        if (!$this->isSafePlayerReply($reply) || stripos($reply, '[STATE]') !== false) {
            return null;
        }

        return sprintf(
            '%s[STATE]KINDNESS=%d;SUSPICION=%d;DEPENDENCY=%d',
            $reply,
            $kindness,
            $suspicion,
            $dependency,
        );
    }

    private function isSafePlayerReply(string $reply): bool
    {
        if ($reply === ''
            || !mb_check_encoding($reply, 'UTF-8')
            || mb_strlen($reply, 'UTF-8') > self::MAX_REPLY_CHARACTERS
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $reply) === 1
            || str_contains($reply, '```')
            || preg_match('/[{}]/u', $reply) === 1
            || preg_match('/<[^>]+>/u', $reply) === 1
            || preg_match('/\A\s*(?:debug|analysis|system|assistant|developer|tool|function)(?:\s+message)?\s*:/ui', $reply) === 1) {
            return false;
        }

        preg_match_all('/[.!?]+(?=\s|\z)/u', $reply, $sentenceEndings);

        return count($sentenceEndings[0]) <= 2;
    }
}
