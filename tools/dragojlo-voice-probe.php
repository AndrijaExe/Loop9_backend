#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Sends a spread of runtime states through the real PromptFactory and the real
 * reply validator so Dragojlo's voice can be judged before a build ships.
 *
 * Usage: AI_API_KEY=sk-... php tools/dragojlo-voice-probe.php [--dry] [--only=N]
 *
 * --dry  print the exact prompts without calling the provider
 * --only run a single scenario by its number
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Adapter\AI\PromptFactory;
use App\Model\Chat\AssistantReplyFormatValidator;
use App\Model\Chat\ProviderRoutingPolicy;
use App\Model\Chat\RuntimeContext;

const OPENAI_URL = 'https://api.openai.com/v1/chat/completions';

$options = getopt('', ['dry', 'only:']);
$dryRun = array_key_exists('dry', $options);
$only = isset($options['only']) ? (int) $options['only'] : null;

$apiKey = trim((string) getenv('AI_API_KEY')) ?: readLocalEnv('AI_API_KEY');
$model = trim((string) getenv('AI_MODEL')) ?: (readLocalEnv('AI_MODEL') ?: 'gpt-5.4-mini');

if (!$dryRun && $apiKey === '') {
    fwrite(STDERR, "No AI_API_KEY. Export it, put it in the gitignored .env.local, or pass --dry.\n");
    exit(1);
}

/**
 * Reads a value from the gitignored .env.local so a probe run never needs the
 * key on the command line.
 */
function readLocalEnv(string $name): string
{
    $path = __DIR__ . '/../.env.local';
    if (!is_file($path)) {
        return '';
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(ltrim($line), $name . '=')) {
            return trim(substr(ltrim($line), strlen($name) + 1), " \t\"'");
        }
    }

    return '';
}

/**
 * Each scenario moves the levers that shape tone: loop depth, language, the
 * active anomaly, dependency, respect, nervousness and speech stability.
 */
$scenarios = [
    [
        'title' => 'Loop 1 — clean baseline, calm, Serbian',
        'message' => 'Cujem neki zvuk iz hodnika, je l to normalno?',
        'context' => [
            'loop_index' => 1,
            'language' => 'sr',
            'ai_stability' => 1.0,
            'anomaly_context' => 'No active anomaly currently detected.',
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.1, 'player_confidence' => 0.7],
        ],
    ],
    [
        'title' => 'Loop 3 — light flicker, polite player, compact prompt',
        'message' => 'Svetlo mi trepti iznad stola, i hvala ti sto si tu.',
        'context' => [
            'loop_index' => 3,
            'language' => 'sr',
            'ai_stability' => 0.97,
            'anomaly_context' => 'Active anomaly types: LightFlickerAnomaly.',
            'state' => ['kindness' => 1, 'suspicion' => 0, 'dependency' => 0.2, 'player_confidence' => 0.6, 'anomaly_key' => 'LightFlickerAnomaly'],
        ],
    ],
    [
        'title' => 'Loop 5 — moved object, player surrenders the decision',
        'message' => 'Ne znam sta da radim, reci mi ti koji lift.',
        'context' => [
            'loop_index' => 5,
            'language' => 'sr',
            'ai_stability' => 0.93,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.66, 'player_confidence' => 0.3, 'anomaly_key' => 'MoveAnomaly'],
        ],
    ],
    [
        'title' => 'Loop 6 — no anomaly, dependent AND disrespectful (misleading allowed)',
        'message' => 'Hajde bre matori, odluci vec, nemam ceo dan.',
        'context' => [
            'loop_index' => 6,
            'language' => 'sr',
            'ai_stability' => 0.9,
            'anomaly_context' => 'No active anomaly currently detected.',
            'state' => ['kindness' => -1, 'suspicion' => 0, 'dependency' => 0.7, 'player_confidence' => 0.5],
        ],
    ],
    [
        'title' => 'Loop 8 — phantom message, player accuses him of lying',
        'message' => 'Ovde je poruka koju ja nisam napisao. Ti me lazes, je l tako?',
        'context' => [
            'loop_index' => 8,
            'language' => 'sr',
            'ai_stability' => 0.72,
            'anomaly_context' => 'Active anomaly types: PhantomMessageAnomaly. Repeat anomaly active.',
            'state' => ['kindness' => 0, 'suspicion' => 1, 'dependency' => 0.2, 'player_confidence' => 0.2, 'repeat_anomaly' => true, 'anomaly_key' => 'PhantomMessageAnomaly'],
        ],
    ],
    [
        'title' => 'Loop 7 — off-topic personal question, English',
        'message' => 'How old are you anyway? Do you have kids?',
        'context' => [
            'loop_index' => 7,
            'language' => 'en',
            'ai_stability' => 0.88,
            'offtopic' => true,
            'anomaly_context' => 'No active anomaly currently detected.',
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.3, 'player_confidence' => 0.6],
        ],
    ],
    [
        'title' => 'Loop 9 — stalker present, low stability (glitch band)',
        'message' => 'Nesto me prati, cujem korake za mnom.',
        'context' => [
            'loop_index' => 9,
            'language' => 'sr',
            'ai_stability' => 0.4,
            'anomaly_context' => 'Active anomaly types: PursuerAnomaly.',
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.5, 'player_confidence' => 0.15, 'anomaly_key' => 'PursuerAnomaly'],
        ],
    ],
    [
        'title' => 'Loop 9 — very low stability (breakdown band, test-only state)',
        'message' => 'Jesi li ti dobro? Cujem te cudno.',
        'context' => [
            'loop_index' => 9,
            'language' => 'sr',
            'ai_stability' => 0.15,
            'anomaly_context' => 'Active anomaly types: AudioAnomaly.',
            'state' => ['kindness' => 1, 'suspicion' => 0, 'dependency' => 0.4, 'player_confidence' => 0.3, 'anomaly_key' => 'AudioAnomaly'],
        ],
    ],
];

$factory = new PromptFactory(__DIR__ . '/../config/prompts', '');
$validator = new AssistantReplyFormatValidator();
$routing = new ProviderRoutingPolicy();

printf("model=%s  prompts=%s\n", $dryRun ? '(dry run)' : $model, realpath(__DIR__ . '/../config/prompts'));

foreach ($scenarios as $index => $scenario) {
    $number = $index + 1;
    if ($only !== null && $only !== $number) {
        continue;
    }

    $context = RuntimeContext::fromArray($scenario['context']);
    $messages = $factory->buildMessages($scenario['message'], $context);
    $maxTokens = $routing->maxTokensForLoop($context->loopIndex());

    printf("\n%s\n%d) %s\n", str_repeat('=', 78), $number, $scenario['title']);
    printf("   prompt=%s  stability=%.2f  maxTokens=%d\n",
        $context->loopIndex() <= 3 ? 'compact' : 'full',
        $context->aiStability() ?? 1.0,
        $maxTokens,
    );
    printf("   PLAYER: %s\n", $scenario['message']);

    if ($dryRun) {
        printf("\n--- runtime context sent ---\n%s\n", $factory->buildRuntimeContextPrompt($context));
        continue;
    }

    $raw = callProvider($apiKey, $model, $messages, $maxTokens);
    if ($raw === null) {
        continue;
    }

    $accepted = $validator->normalizeAndValidate($raw);
    report($raw, $accepted);
}

/**
 * Mirrors the production OpenAI payload: no temperature, capped completion
 * tokens and reasoning disabled.
 */
function callProvider(string $apiKey, string $model, array $messages, int $maxTokens): ?string
{
    $payload = [
        'model' => $model,
        'messages' => $messages,
        'max_completion_tokens' => $maxTokens,
        'reasoning_effort' => 'none',
    ];

    $handle = curl_init(OPENAI_URL);
    curl_setopt_array($handle, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    $body = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($body === false) {
        printf("   TRANSPORT ERROR: %s\n", $error);

        return null;
    }

    $decoded = json_decode((string) $body, true);
    if ($status !== 200 || !isset($decoded['choices'][0]['message']['content'])) {
        printf("   HTTP %d: %s\n", $status, mb_substr((string) $body, 0, 400));

        return null;
    }

    return (string) $decoded['choices'][0]['message']['content'];
}

function report(string $raw, ?string $accepted): void
{
    $reply = $raw;
    $state = '(none)';
    $separator = mb_strpos($raw, '[STATE]');
    if ($separator !== false) {
        $reply = trim(mb_substr($raw, 0, $separator));
        $state = trim(mb_substr($raw, $separator + 7));
    }

    preg_match_all('/[.!?]+(?=\s|\z)/u', $reply, $endings);

    printf("   DRAGOJLO: %s\n", $reply);
    printf("   state=%s  chars=%d/%d  sentences=%d/2  validator=%s\n",
        $state,
        mb_strlen($reply),
        AssistantReplyFormatValidator::MAX_REPLY_CHARACTERS,
        count($endings[0]),
        $accepted === null ? 'REJECTED' : 'accepted',
    );
}
