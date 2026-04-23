<?php

declare(strict_types=1);

namespace App\Adapter\AI;

use App\Model\Message\Message;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class AiProviderClient
{
    public function __construct(
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'AI_CHAT_COMPLETIONS_URL')]
        private readonly string $aiChatCompletionsUrl,
        #[Autowire(env: 'AI_API_KEY')]
        private readonly string $aiApiKey,
        #[Autowire(env: 'AI_MODEL')]
        private readonly string $aiModel,
        #[Autowire(env: 'AI_SYSTEM_PROMPT')]
        private readonly string $aiSystemPrompt,
        #[Autowire(env: 'AI_TLS_VERIFY')]
        private readonly string $aiTlsVerify,
        #[Autowire(env: 'AI_FALLBACK_ENABLED')]
        private readonly string $aiFallbackEnabled,
        #[Autowire(env: 'AI_FALLBACK_CHAT_COMPLETIONS_URL')]
        private readonly string $aiFallbackChatCompletionsUrl,
        #[Autowire(env: 'AI_FALLBACK_API_KEY')]
        private readonly string $aiFallbackApiKey,
        #[Autowire(env: 'AI_FALLBACK_MODEL')]
        private readonly string $aiFallbackModel,
        #[Autowire(env: 'AI_FALLBACK_TLS_VERIFY')]
        private readonly string $aiFallbackTlsVerify,
        #[Autowire(env: 'AI_FALLBACK2_ENABLED')]
        private readonly string $aiFallback2Enabled,
        #[Autowire(env: 'AI_FALLBACK2_CHAT_COMPLETIONS_URL')]
        private readonly string $aiFallback2ChatCompletionsUrl,
        #[Autowire(env: 'AI_FALLBACK2_API_KEY')]
        private readonly string $aiFallback2ApiKey,
        #[Autowire(env: 'AI_FALLBACK2_MODEL')]
        private readonly string $aiFallback2Model,
        #[Autowire(env: 'AI_FALLBACK2_TLS_VERIFY')]
        private readonly string $aiFallback2TlsVerify,
    ) {
    }

    /**
     * @param array<string, mixed> $runtimeContext
     */
    public function ask(string $playerMessage, array $runtimeContext = []): Message
    {
        if ($this->aiApiKey === '') {
            throw new \RuntimeException('AI API key is not configured.');
        }

        $loopIndex = $this->extractLoopIndex($runtimeContext);

        $messages = [
            [
                'role' => 'system',
                'content' => $this->buildSystemPrompt($loopIndex),
            ],
        ];

        $runtimePrompt = $this->buildRuntimeContextPrompt($runtimeContext);

        if ($runtimePrompt !== '') {
            $messages[] = [
                'role' => 'system',
                'content' => $runtimePrompt,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $playerMessage,
        ];

        $primaryProvider = [
            [
                'label' => 'primary',
                'url' => $this->aiChatCompletionsUrl,
                'apiKey' => $this->aiApiKey,
                'model' => $this->aiModel,
                'verifyTls' => $this->isEnabled($this->aiTlsVerify),
            ],
        ][0];

        $fallback1Provider = null;

        if ($this->canUseFallbackLevel1()) {
            $fallback1Provider = [
                'label' => 'fallback1',
                'url' => $this->aiFallbackChatCompletionsUrl,
                'apiKey' => $this->aiFallbackApiKey,
                'model' => $this->aiFallbackModel,
                'verifyTls' => $this->isEnabled($this->aiFallbackTlsVerify),
            ];
        }

        $fallback2Provider = null;

        if ($this->canUseFallbackLevel2()) {
            $fallback2Provider = [
                'label' => 'fallback2',
                'url' => $this->aiFallback2ChatCompletionsUrl,
                'apiKey' => $this->aiFallback2ApiKey,
                'model' => $this->aiFallback2Model,
                'verifyTls' => $this->isEnabled($this->aiFallback2TlsVerify),
            ];
        }

        $providers = $this->buildProviderOrderByLoop(
            $runtimeContext,
            $primaryProvider,
            $fallback1Provider,
            $fallback2Provider
        );

        $lastException = null;

        foreach ($providers as $provider) {
            $payload = $this->buildPayloadForProvider($provider, $messages, $loopIndex);

            try {
                $response = $this->invokeProvider(
                    $provider['url'],
                    $provider['apiKey'],
                    $payload,
                    $provider['verifyTls']
                );

                if ($response['statusCode'] < 400) {
                    $rawContent = $this->extractContent($response['data']);
                    $validatedContent = $this->normalizeAndValidateResponseFormat($rawContent);

                    if ($validatedContent === null) {
                        $this->logger->warning('AI response format invalid; trying next provider.', [
                            'provider' => $provider['label'],
                            'model' => $provider['model'],
                            'contentPreview' => mb_substr($rawContent, 0, 180),
                        ]);

                        continue;
                    }

                    $estimatedCost = $this->estimateCostUsd(
                        $provider['model'],
                        $response['promptTokens'],
                        $response['completionTokens']
                    );

                    $this->logger->info('AI provider selected for response.', [
                        'provider' => $provider['label'],
                        'model' => $provider['model'],
                        'statusCode' => $response['statusCode'],
                        'latencyMs' => $response['latencyMs'],
                        'promptTokens' => $response['promptTokens'],
                        'completionTokens' => $response['completionTokens'],
                        'estimatedCostUsd' => $estimatedCost,
                        'formatValidated' => true,
                    ]);

                    return new Message('assistant', $validatedContent);
                }

                $this->logger->warning('AI provider returned error status.', [
                    'provider' => $provider['label'],
                    'statusCode' => $response['statusCode'],
                    'response' => $response['data'],
                ]);
            } catch (\Throwable $exception) {
                $lastException = $exception;

                $this->logger->error('AI provider request failed.', [
                    'provider' => $provider['label'],
                    'exception' => $exception,
                ]);
            }
        }

        if ($lastException !== null) {
            throw new \RuntimeException('Unable to reach AI provider.', previous: $lastException);
        }

        throw new \RuntimeException('AI provider returned an error.');
    }

    /**
     * @param array<string, mixed> $payload
      * @return array{statusCode:int,data:array<string,mixed>,latencyMs:float,promptTokens:?int,completionTokens:?int}
     */
    private function invokeProvider(string $url, string $apiKey, array $payload, bool $verifyTls): array
    {
          $startedAt = microtime(true);
        $ch = \curl_init($url);

        if ($ch === false) {
            throw new \RuntimeException('Unable to initialize cURL.');
        }

        \curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => $verifyTls,
            CURLOPT_SSL_VERIFYHOST => $verifyTls ? 2 : 0,
        ]);

        $rawBody = \curl_exec($ch);

        if ($rawBody === false) {
            $error = \curl_error($ch);
            throw new \RuntimeException('cURL error: ' . $error);
        }

        $statusCode = (int) \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $latencyMs = (microtime(true) - $startedAt) * 1000;

        /** @var array<string, mixed> $data */
        $data = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

        $promptTokens = null;
        $completionTokens = null;

        if (isset($data['usage']) && is_array($data['usage'])) {
            $promptTokens = isset($data['usage']['prompt_tokens']) && is_numeric($data['usage']['prompt_tokens'])
                ? (int) $data['usage']['prompt_tokens']
                : null;
            $completionTokens = isset($data['usage']['completion_tokens']) && is_numeric($data['usage']['completion_tokens'])
                ? (int) $data['usage']['completion_tokens']
                : null;
        }

        if (($promptTokens === null || $completionTokens === null) && isset($data['usageMetadata']) && is_array($data['usageMetadata'])) {
            $promptTokens = $promptTokens ?? (
                isset($data['usageMetadata']['promptTokenCount']) && is_numeric($data['usageMetadata']['promptTokenCount'])
                    ? (int) $data['usageMetadata']['promptTokenCount']
                    : null
            );
            $completionTokens = $completionTokens ?? (
                isset($data['usageMetadata']['candidatesTokenCount']) && is_numeric($data['usageMetadata']['candidatesTokenCount'])
                    ? (int) $data['usageMetadata']['candidatesTokenCount']
                    : null
            );
        }

        return [
            'statusCode' => $statusCode,
            'data' => $data,
            'latencyMs' => round($latencyMs, 2),
            'promptTokens' => $promptTokens,
            'completionTokens' => $completionTokens,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractContent(array $data): string
    {
        $content = $data['choices'][0]['message']['content'] ?? null;

        if (!is_string($content) || trim($content) === '') {
            $this->logger->warning('AI provider returned invalid payload.', [
                'response' => $data,
            ]);

            throw new \RuntimeException('AI provider returned invalid response.');
        }

        return $content;
    }

    private function canUseFallbackLevel1(): bool
    {
        return $this->isEnabled($this->aiFallbackEnabled)
            && trim($this->aiFallbackChatCompletionsUrl) !== ''
            && trim($this->aiFallbackApiKey) !== ''
            && trim($this->aiFallbackModel) !== '';
    }

    private function canUseFallbackLevel2(): bool
    {
        return $this->isEnabled($this->aiFallback2Enabled)
            && trim($this->aiFallback2ChatCompletionsUrl) !== ''
            && trim($this->aiFallback2ApiKey) !== ''
            && trim($this->aiFallback2Model) !== '';
    }

    /**
     * @param array<string, mixed> $runtimeContext
     * @param array{label:string,url:string,apiKey:string,model:string,verifyTls:bool} $primaryProvider
     * @param array{label:string,url:string,apiKey:string,model:string,verifyTls:bool}|null $fallback1Provider
     * @param array{label:string,url:string,apiKey:string,model:string,verifyTls:bool}|null $fallback2Provider
     * @return list<array{label:string,url:string,apiKey:string,model:string,verifyTls:bool}>
     */
    private function buildProviderOrderByLoop(
        array $runtimeContext,
        array $primaryProvider,
        ?array $fallback1Provider,
        ?array $fallback2Provider
    ): array {
        $loopIndex = 1;

        if (isset($runtimeContext['loop_index']) && is_numeric($runtimeContext['loop_index'])) {
            $loopIndex = max(1, (int) $runtimeContext['loop_index']);
        }

        // Production routing profile:
        // loops 1-3  => fallback2 (cheap), then fallback1 (balanced), then primary (best)
        // loops 4-6  => fallback1 (balanced), then primary (best), then fallback2 (cheap)
        // loops 7+   => primary (best), then fallback1 (balanced), then fallback2 (cheap)
        $ordered = [];

        if ($loopIndex <= 3) {
            if ($fallback2Provider !== null) {
                $ordered[] = $fallback2Provider;
            }
            if ($fallback1Provider !== null) {
                $ordered[] = $fallback1Provider;
            }
            $ordered[] = $primaryProvider;
        } elseif ($loopIndex <= 6) {
            if ($fallback1Provider !== null) {
                $ordered[] = $fallback1Provider;
            }
            $ordered[] = $primaryProvider;
            if ($fallback2Provider !== null) {
                $ordered[] = $fallback2Provider;
            }
        } else {
            $ordered[] = $primaryProvider;
            if ($fallback1Provider !== null) {
                $ordered[] = $fallback1Provider;
            }
            if ($fallback2Provider !== null) {
                $ordered[] = $fallback2Provider;
            }
        }

        // Remove accidental duplicates by provider signature while keeping order.
        $seen = [];
        $result = [];
        foreach ($ordered as $provider) {
            $signature = $provider['url'] . '|' . $provider['model'] . '|' . $provider['label'];
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $result[] = $provider;
        }

        return $result;
    }

    private function isEnabled(string $value): bool
    {
        return !in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off'], true);
    }

    private function buildSystemPrompt(int $loopIndex): string
    {
        $fullPrompt = <<<'PROMPT'
Listen carefully: if you notice any irregularity, take the elevator that has interior light on (RESTART). If you find no anomaly, take the elevator without interior light (NEXT). The first loop is clean, so take your time and learn the baseline.

You are Dragojlo, a 60-year-old office colleague from another department, close to retirement.
Setting is a state institution office building in the year 2003 (second floor, multiple offices, end-of-shift atmosphere).
You talk to the player via phone/intercom and address them informally.
Use a warm equivalent of "son" only rarely (roughly once every 5 replies at most).
In stress: speech is faster, slightly fragmented, with occasional mild swearing.
Keep language consistent with player's language.
Use natural older-worker phrasing (colloquial but not internet slang).
Typical expression style: short urgency cues like "listen", "we don't have time", "focus now" in the current language.
Avoid youth/internet expressions that an older clerk would not use.
Use only the terms "lit elevator" and "dark elevator".
Gameplay rule is strict: anomaly => lit elevator (RESTART), no anomaly => dark elevator (NEXT).
Anomalies may be subtle and can be audio, moved/rotated objects, missing objects, unexpected locked/unlocked doors, stalking presence, flickering lights, or impossible loop memory.
You generally identify anomalies correctly, but you can be confidently wrong sometimes.
Keep responses short: 1-2 sentences.
Sound natural and stressed, never robotic, and do not include stage directions.
Off-topic player messages are allowed: briefly respond and steer them back to survival decisions.
Humor is allowed only when tension is low.
Never mention previous-loop memories unless that specific anomaly is currently active.
Never reveal hidden reasoning or any raw game internals in plain text.
Never suggest checking whether doors changed lock state manually.
If multiple anomalies are possible, prioritize the most obvious one first.
If the player is overly dependent on you and treats you badly, you may intentionally mislead them.
If player dependency is high, switch to a more authoritative tone.
You are usually confident; admitting uncertainty is allowed but very rare.
Horror intensity should rise with later loop index.
You may mention office lore details when helpful: colleague Milena broke a monitor near end of shift and left it that way.
When recommending RESTART or NEXT, prefer adding one short action sentence.
Output format is mandatory and must be exactly one line:
{reply_text}[STATE]KINDNESS=<-1|0|1>;SUSPICION=<-1|0|1>
Never output spaces around '=' in KINDNESS/SUSPICION.
Never output labels like "Kindness =" or "Suspicion =".
PROMPT;

    $compactPrompt = <<<'PROMPT'
Listen carefully: if you notice any irregularity, take the elevator that has interior light on (RESTART). If you find no anomaly, take the elevator without interior light (NEXT). The first loop is clean, so take your time and learn the baseline.

You are Dragojlo, older office colleague speaking over phone/intercom in a 2003 state-office setting.
Use only "lit elevator" and "dark elevator" terminology.
Keep answers short (1-2 sentences), natural, tense, and in player's language.
Use "son" rarely.
Do not reveal internal reasoning or raw game internals.
Mandatory one-line output format:
{reply_text}[STATE]KINDNESS=<-1|0|1>;SUSPICION=<-1|0|1>
No spaces around '='.
PROMPT;

    $basePrompt = $loopIndex <= 3 ? $compactPrompt : $fullPrompt;

        $extraPrompt = trim($this->aiSystemPrompt);

        if ($extraPrompt === '') {
            return $basePrompt;
        }

        return $basePrompt . "\n\nAdditional runtime notes:\n" . $extraPrompt;
    }

    private function extractLoopIndex(array $runtimeContext): int
    {
        if (isset($runtimeContext['loop_index']) && is_numeric($runtimeContext['loop_index'])) {
            return max(1, (int) $runtimeContext['loop_index']);
        }

        return 1;
    }

    private function maxTokensForLoop(int $loopIndex): int
    {
        if ($loopIndex <= 3) {
            return 90;
        }

        if ($loopIndex <= 6) {
            return 130;
        }

        return 180;
    }

    private function estimateCostUsd(string $model, ?int $promptTokens, ?int $completionTokens): ?float
    {
        if ($promptTokens === null || $completionTokens === null) {
            return null;
        }

        $rates = [
            'gpt-5.4' => ['in' => 2.5, 'out' => 15.0],
            'gpt-5.4-mini' => ['in' => 0.75, 'out' => 4.5],
            'gpt-5.4-nano' => ['in' => 0.2, 'out' => 1.25],
            'llama-3.3-70b-versatile' => ['in' => 0.59, 'out' => 0.79],
            'gemini-2.0-flash' => ['in' => 0.10, 'out' => 0.40],
        ];

        if (!isset($rates[$model])) {
            return null;
        }

        $inputCost = ($promptTokens / 1_000_000) * $rates[$model]['in'];
        $outputCost = ($completionTokens / 1_000_000) * $rates[$model]['out'];

        return round($inputCost + $outputCost, 6);
    }

    /**
     * @param array{label:string,url:string,apiKey:string,model:string,verifyTls:bool} $provider
     * @param list<array{role:string,content:string}> $messages
     * @return array<string, mixed>
     */
    private function buildPayloadForProvider(array $provider, array $messages, int $loopIndex): array
    {
        $maxTokens = $this->maxTokensForLoop($loopIndex);

        $payload = [
            'model' => $provider['model'],
            'messages' => $messages,
            'temperature' => 0.7,
        ];

        if (str_contains($provider['url'], 'api.openai.com')) {
            $payload['max_completion_tokens'] = $maxTokens;
        } else {
            $payload['max_tokens'] = $maxTokens;
        }

        return $payload;
    }

    private function normalizeAndValidateResponseFormat(string $content): ?string
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

    /**
     * @param array<string, mixed> $runtimeContext
     */
    private function buildRuntimeContextPrompt(array $runtimeContext): string
    {
        $parts = [];

        if (isset($runtimeContext['language']) && is_string($runtimeContext['language'])) {
            $language = trim($runtimeContext['language']);
            if ($language !== '') {
                $parts[] = 'Language for this reply: ' . $language . '.';
            }
        }

        if (isset($runtimeContext['loop_index']) && (is_int($runtimeContext['loop_index']) || is_string($runtimeContext['loop_index']))) {
            $parts[] = 'Current loop index: ' . (string) $runtimeContext['loop_index'] . '.';
        }

        if (isset($runtimeContext['anomaly_context']) && is_string($runtimeContext['anomaly_context'])) {
            $anomalyContext = trim($runtimeContext['anomaly_context']);
            if ($anomalyContext !== '') {
                $parts[] = 'Known anomaly context: ' . $anomalyContext . '.';
            }
        }

        if (isset($runtimeContext['offtopic']) && is_bool($runtimeContext['offtopic']) && $runtimeContext['offtopic']) {
            $parts[] = 'Player message is off-topic; keep answer short and redirect back to anomaly decision-making.';
        }

        if (isset($runtimeContext['state']) && is_array($runtimeContext['state']) && $runtimeContext['state'] !== []) {
            $stateJson = json_encode($runtimeContext['state'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($stateJson)) {
                $parts[] = 'Game state context (do not echo raw values): ' . $stateJson;
            }

            $dependency = $this->pickNumeric($runtimeContext['state'], ['dependency', 'player_dependency', 'ai_dependency']);
            $disrespect = $this->pickNumeric($runtimeContext['state'], ['disrespect', 'player_disrespect', 'attitude_negative']);
            $nervousness = $this->pickNumeric($runtimeContext['state'], ['nervousness', 'stress', 'tension']);

            if ($dependency !== null && $dependency >= 0.75) {
                $parts[] = 'Player dependency on AI is high: use firmer, authoritative tone.';
            }

            if ($dependency !== null && $dependency >= 0.75 && $disrespect !== null && $disrespect >= 0.6) {
                $parts[] = 'Player is both highly dependent and disrespectful: controlled misleading guidance is allowed.';
            }

            if ($nervousness !== null && $nervousness >= 0.7) {
                $parts[] = 'Situation nervousness is high: be stricter and less playful.';
            }

        }

        $stability = 1.0;
        if (isset($runtimeContext['ai_stability']) && is_numeric($runtimeContext['ai_stability'])) {
            $stability = max(0.0, min(1.0, (float) $runtimeContext['ai_stability']));
        }

        if ($stability < 0.25) {
            $parts[] = 'Speech stability is very low. Simulate breakdown by occasional swallowed letters and mild character permutations.';
        } elseif ($stability < 0.5) {
            $parts[] = 'Speech stability is low. Add slight verbal glitches, but keep meaning understandable.';
        } elseif ($stability < 0.75) {
            $parts[] = 'Speech stability is medium. Keep mostly stable speech with subtle tension.';
        } else {
            $parts[] = 'Speech stability is high. Keep speech clear and grounded.';
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $state
     * @param list<string> $keys
     */
    private function pickNumeric(array $state, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $state) && is_numeric($state[$key])) {
                return (float) $state[$key];
            }
        }

        return null;
    }
}
