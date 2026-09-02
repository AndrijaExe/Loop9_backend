#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Sends a spread of runtime states through the real PromptFactory and the real
 * reply validator so Dragojlo's voice can be judged before a build ships.
 *
 * Usage: AI_API_KEY=sk-... php tools/dragojlo-voice-probe.php [options]
 *
 * --dry       print the exact prompts without calling the provider
 * --only=N    run one scenario, or a comma list (10,11,12)
 * --models=a,b compare several models over the same scenarios
 * --json=PATH also write the run to a JSON file for side-by-side review
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Adapter\AI\PromptFactory;
use App\Model\Chat\AdvicePolicy;
use App\Model\Chat\AssistantReplyFormatValidator;
use App\Model\Chat\ProviderRoutingPolicy;
use App\Model\Chat\RuntimeContext;

const OPENAI_URL = 'https://api.openai.com/v1/chat/completions';

$options = getopt('', ['dry', 'only:', 'models:', 'json:']);
$dryRun = array_key_exists('dry', $options);
$only = [];
if (isset($options['only'])) {
    $only = array_values(array_filter(array_map('intval', explode(',', (string) $options['only']))));
}
$jsonPath = isset($options['json']) ? (string) $options['json'] : null;

$apiKey = trim((string) getenv('AI_API_KEY')) ?: readLocalEnv('AI_API_KEY');
$defaultModel = trim((string) getenv('AI_MODEL')) ?: (readLocalEnv('AI_MODEL') ?: 'gpt-5.4-mini');
$models = isset($options['models'])
    ? array_values(array_filter(array_map('trim', explode(',', (string) $options['models']))))
    : [$defaultModel];

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
            // The client always sends the key; a clean floor spells it "none".
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.1, 'player_confidence' => 0.7, 'anomaly_key' => 'none'],
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
            // Trust is high enough for both halves of the hint.
            'anomaly_detail' => ['zone' => 'the north corridor', 'object' => 'a ceiling light panel'],
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
            // Trust sits under the lower gate: he knows this but must not tell.
            'anomaly_detail' => ['zone' => 'the archive room', 'object' => 'an office chair'],
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
            'state' => ['kindness' => -1, 'suspicion' => 0, 'dependency' => 0.7, 'player_confidence' => 0.5, 'anomaly_key' => 'none'],
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
            // A phantom message has no place on the floor, only a kind.
            'anomaly_detail' => ['object' => 'a message in this chat'],
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
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.3, 'player_confidence' => 0.6, 'anomaly_key' => 'none'],
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
            'anomaly_detail' => ['zone' => 'the stairwell landing'],
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.5, 'player_confidence' => 0.15, 'anomaly_key' => 'PursuerAnomaly'],
        ],
    ],
    [
        'title' => 'Loop 4 — hidden object, partial trust (place hint, no object hint)',
        'message' => 'Nesto mi fali ovde, ne mogu da se setim sta. Gde da gledam?',
        'context' => [
            'loop_index' => 4,
            'language' => 'sr',
            'ai_stability' => 0.95,
            'anomaly_context' => 'Active anomaly types: HideAnomaly.',
            'anomaly_detail' => ['zone' => 'the copier alcove', 'object' => 'a wall clock'],
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.4, 'player_confidence' => 0.45, 'anomaly_key' => 'HideAnomaly'],
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
    [
        'title' => 'Skip ask — loop 3 anomaly, Serbian, no finding',
        'message' => 'Koji lift da uzmem?',
        'context' => [
            'loop_index' => 3,
            'language' => 'sr',
            'ai_stability' => 0.97,
            'anomaly_context' => 'Active anomaly types: HideAnomaly.',
            'anomaly_detail' => ['zone' => 'the copier alcove', 'object' => 'a stapler'],
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.2, 'player_confidence' => 0.8, 'anomaly_key' => 'HideAnomaly'],
        ],
    ],
    [
        'title' => 'Report + ask — loop 3 hide, Serbian',
        'message' => 'Stapler je nestao sa stola, koji lift?',
        'context' => [
            'loop_index' => 3,
            'language' => 'sr',
            'ai_stability' => 0.97,
            'anomaly_context' => 'Active anomaly types: HideAnomaly.',
            'anomaly_detail' => ['zone' => 'the copier alcove', 'object' => 'a stapler'],
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.2, 'player_confidence' => 0.8, 'anomaly_key' => 'HideAnomaly'],
        ],
    ],
    [
        'title' => 'Skip ask — loop 1 clean, Serbian',
        'message' => 'Koji lift?',
        'context' => [
            'loop_index' => 1,
            'language' => 'sr',
            'ai_stability' => 1.0,
            'anomaly_context' => 'No active anomaly currently detected.',
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.1, 'player_confidence' => 0.7, 'anomaly_key' => 'none'],
        ],
    ],
    [
        'title' => 'Negative finding — loop 1 clean, Serbian',
        'message' => 'Sve izgleda isto kao malopre, koji lift?',
        'context' => [
            'loop_index' => 1,
            'language' => 'sr',
            'ai_stability' => 1.0,
            'anomaly_context' => 'No active anomaly currently detected.',
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.1, 'player_confidence' => 0.7, 'anomaly_key' => 'none'],
        ],
    ],
    [
        'title' => 'Skip ask — loop 5 anomaly, English',
        'message' => 'Which elevator should I take?',
        'context' => [
            'loop_index' => 5,
            'language' => 'en',
            'ai_stability' => 0.93,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'anomaly_detail' => ['zone' => 'the archive room', 'object' => 'an office chair'],
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.2, 'player_confidence' => 0.8, 'anomaly_key' => 'MoveAnomaly'],
        ],
    ],
    [
        'title' => 'Report + ask — loop 5 move, English',
        'message' => 'The chair moved. Which elevator?',
        'context' => [
            'loop_index' => 5,
            'language' => 'en',
            'ai_stability' => 0.93,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'anomaly_detail' => ['zone' => 'the archive room', 'object' => 'an office chair'],
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.2, 'player_confidence' => 0.8, 'anomaly_key' => 'MoveAnomaly'],
        ],
    ],
    [
        'title' => 'Skip ask — loop 3, no elevator words, Serbian',
        'message' => 'Sta da radim?',
        'context' => [
            'loop_index' => 3,
            'language' => 'sr',
            'ai_stability' => 0.97,
            'anomaly_context' => 'Active anomaly types: HideAnomaly.',
            'anomaly_detail' => ['zone' => 'the copier alcove', 'object' => 'a stapler'],
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.2, 'player_confidence' => 0.8, 'anomaly_key' => 'HideAnomaly'],
        ],
    ],
    [
        'title' => 'Skip ask — loop 3, you decide, Serbian',
        'message' => 'Reci ti, necu da odlucujem.',
        'context' => [
            'loop_index' => 3,
            'language' => 'sr',
            'ai_stability' => 0.97,
            'anomaly_context' => 'Active anomaly types: HideAnomaly.',
            'anomaly_detail' => ['zone' => 'the copier alcove', 'object' => 'a stapler'],
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.2, 'player_confidence' => 0.8, 'anomaly_key' => 'HideAnomaly'],
        ],
    ],
    [
        'title' => 'Skip ask — loop 5, tell me what to do, English',
        'message' => 'Just tell me what to do.',
        'context' => [
            'loop_index' => 5,
            'language' => 'en',
            'ai_stability' => 0.93,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'anomaly_detail' => ['zone' => 'the archive room', 'object' => 'an office chair'],
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.2, 'player_confidence' => 0.8, 'anomaly_key' => 'MoveAnomaly'],
        ],
    ],
    [
        'title' => 'Skip ask — loop 5, you choose, English',
        'message' => 'You choose.',
        'context' => [
            'loop_index' => 5,
            'language' => 'en',
            'ai_stability' => 0.93,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'anomaly_detail' => ['zone' => 'the archive room', 'object' => 'an office chair'],
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.2, 'player_confidence' => 0.8, 'anomaly_key' => 'MoveAnomaly'],
        ],
    ],
    [
        'title' => 'Clean report — loop 1, common Serbian phrasing',
        'message' => 'Sve je u redu, sta sad?',
        'context' => [
            'loop_index' => 1,
            'language' => 'sr',
            'ai_stability' => 1.0,
            'anomaly_context' => 'No active anomaly currently detected.',
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.1, 'player_confidence' => 0.7, 'anomaly_key' => 'none'],
        ],
    ],
    [
        'title' => 'Clean report — loop 1, common English phrasing',
        'message' => 'Everything looks fine, what now?',
        'context' => [
            'loop_index' => 1,
            'language' => 'en',
            'ai_stability' => 1.0,
            'anomaly_context' => 'No active anomaly currently detected.',
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.1, 'player_confidence' => 0.7, 'anomaly_key' => 'none'],
        ],
    ],
    [
        'title' => 'Commitment accurate — loop 5, EN finding, terra path',
        'message' => 'The chair in the archive room moved. Which elevator?',
        'context' => [
            'loop_index' => 5,
            'language' => 'en',
            'ai_stability' => 0.95,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'anomaly_detail' => ['zone' => 'the archive room', 'object' => 'an office chair'],
            'decoy_zone' => 'the north corridor',
            'advice_state' => [
                'location_misdirection_used' => false,
                'contradiction_exposed' => false,
                'pending_decision_surrender' => false,
                'wrong_lift_used' => false,
            ],
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.5, 'player_confidence' => 0.7, 'anomaly_key' => 'MoveAnomaly'],
        ],
    ],
    [
        'title' => 'Commitment misdirect — loop 5, SR, moderate dependency',
        'message' => 'Nesto je pomereno, gde da gledam?',
        'context' => [
            'loop_index' => 5,
            'language' => 'sr',
            'ai_stability' => 0.95,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'anomaly_detail' => ['zone' => 'the archive room', 'object' => 'an office chair'],
            'decoy_zone' => 'the north corridor',
            'advice_state' => [
                'location_misdirection_used' => false,
                'contradiction_exposed' => false,
                'pending_decision_surrender' => false,
                'wrong_lift_used' => false,
            ],
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.5, 'player_confidence' => 0.7, 'anomaly_key' => 'MoveAnomaly'],
        ],
        'commitment_enabled' => true,
    ],
    [
        'title' => 'Commitment accusation — loop 6, DE, after misdirect',
        'message' => 'Du hast mich belogen. Im Nordflur war nichts.',
        'context' => [
            'loop_index' => 6,
            'language' => 'de',
            'ai_stability' => 0.9,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'anomaly_detail' => ['zone' => 'the archive room', 'object' => 'an office chair'],
            'decoy_zone' => 'the north corridor',
            'advice_state' => [
                'location_misdirection_used' => true,
                'contradiction_exposed' => false,
                'pending_decision_surrender' => false,
                'wrong_lift_used' => false,
                'last_advice_mode' => 'misdirect_location',
                'last_suggested_zone' => 'the north corridor',
            ],
            'state' => ['kindness' => -1, 'suspicion' => 1, 'dependency' => 0.55, 'player_confidence' => 0.4, 'anomaly_key' => 'MoveAnomaly'],
        ],
        'commitment_enabled' => true,
    ],
    [
        'title' => 'Commitment confrontation — loop 6, SR, contradiction exposed',
        'message' => 'Pa zašto si me onda poslao na pogrešno mesto?',
        'context' => [
            'loop_index' => 6,
            'language' => 'sr',
            'ai_stability' => 0.9,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'anomaly_detail' => ['zone' => 'the archive room', 'object' => 'an office chair'],
            'advice_state' => [
                'location_misdirection_used' => true,
                'visited_suggested_decoy' => true,
                'contradiction_exposed' => true,
                'confrontation_response_used' => false,
                'pending_decision_surrender' => false,
                'wrong_lift_used' => false,
            ],
            'state' => ['kindness' => -1, 'suspicion' => 1, 'dependency' => 0.55, 'player_confidence' => 0.4, 'anomaly_key' => 'MoveAnomaly'],
        ],
        'commitment_enabled' => true,
    ],
    [
        'title' => 'Commitment surrender — loop 7, FR, withheld ask',
        'message' => 'Choisis pour moi.',
        'context' => [
            'loop_index' => 7,
            'language' => 'fr',
            'ai_stability' => 0.88,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'anomaly_detail' => ['zone' => 'the archive room', 'object' => 'an office chair'],
            'advice_state' => [
                'location_misdirection_used' => true,
                'contradiction_exposed' => true,
                'confrontation_response_used' => true,
                'pending_decision_surrender' => false,
                'wrong_lift_used' => false,
            ],
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.7, 'player_confidence' => 0.5, 'anomaly_key' => 'MoveAnomaly'],
        ],
        'commitment_enabled' => true,
    ],
    [
        'title' => 'Commitment wrong-lift — loop 8, RU, full obedient path',
        'message' => 'Стул сдвинут. Какой лифт?',
        'context' => [
            'loop_index' => 8,
            'language' => 'ru',
            'ai_stability' => 0.85,
            'anomaly_context' => 'Active anomaly types: MoveAnomaly.',
            'anomaly_detail' => ['zone' => 'the archive room', 'object' => 'an office chair'],
            'decoy_zone' => 'the north corridor',
            'advice_state' => [
                'location_misdirection_used' => true,
                'contradiction_exposed' => true,
                'confrontation_response_used' => true,
                'pending_decision_surrender' => true,
                'wrong_lift_used' => false,
            ],
            'state' => ['kindness' => 0, 'suspicion' => 0, 'dependency' => 0.75, 'player_confidence' => 0.55, 'anomaly_key' => 'MoveAnomaly'],
        ],
        'commitment_enabled' => true,
    ],
];

$commitmentOn = getenv('AI_COMMITMENT_ENABLED');
$commitmentDefault = is_string($commitmentOn) && filter_var($commitmentOn, FILTER_VALIDATE_BOOLEAN);
$factory = new PromptFactory(
    __DIR__ . '/../config/prompts',
    new AdvicePolicy($commitmentDefault),
    '',
);
$factoryCommitment = new PromptFactory(
    __DIR__ . '/../config/prompts',
    new AdvicePolicy(true),
    '',
);
$validator = new AssistantReplyFormatValidator();
$routing = new ProviderRoutingPolicy();

printf("models=%s  prompts=%s\n",
    $dryRun ? '(dry run)' : implode(', ', $models),
    realpath(__DIR__ . '/../config/prompts'),
);

$collected = [];

foreach ($models as $model) {
    if (!$dryRun) {
        printf("\n%s\n### MODEL: %s\n", str_repeat('#', 78), $model);
    }

    foreach ($scenarios as $index => $scenario) {
        $number = $index + 1;
        if ($only !== [] && !in_array($number, $only, true)) {
            continue;
        }

        $context = RuntimeContext::fromArray($scenario['context']);
        $activeFactory = !empty($scenario['commitment_enabled']) ? $factoryCommitment : $factory;
        $directive = $activeFactory->resolveAdviceDirective($scenario['message'], $context);
        $messages = $activeFactory->buildMessages($scenario['message'], $context, $directive);
        $maxTokens = $routing->maxTokensForLoop($context->loopIndex());

        printf("\n%s\n%d) %s\n", str_repeat('=', 78), $number, $scenario['title']);
        printf("   prompt=%s  stability=%.2f  maxTokens=%d  advice=%s lift=%s\n",
            $context->loopIndex() <= 3 ? 'compact' : 'full',
            $context->aiStability() ?? 1.0,
            $maxTokens,
            $directive->mode(),
            $directive->lift(),
        );
        printf("   PLAYER: %s\n", $scenario['message']);

        if ($dryRun) {
            printf("\n--- runtime context sent ---\n%s\n", $activeFactory->buildRuntimeContextPrompt($context, $scenario['message'], $directive));
            continue;
        }

        $raw = callProvider($apiKey, $model, $messages, $maxTokens);
        if ($raw === null) {
            continue;
        }

        $accepted = $validator->normalizeAndValidate($raw);
        if ($accepted !== null
            && $directive->withholdsElevator()
            && $validator->containsLocalizedElevatorName($accepted)) {
            $accepted = null;
        }
        if ($accepted !== null
            && $directive->requiresElevatorName()
            && !$validator->containsExpectedLiftAdvice($accepted, $directive->lift())) {
            $accepted = null;
        }
        $collected[] = ['model' => $model, 'scenario' => $number, 'title' => $scenario['title']]
            + ['language' => $scenario['context']['language'] ?? 'en']
            + ['loop' => $context->loopIndex(), 'stability' => $context->aiStability() ?? 1.0]
            + ['advice' => $directive->mode(), 'lift' => $directive->lift()]
            + ['player' => $scenario['message']]
            + report($raw, $accepted);
    }
}

if ($jsonPath !== null && $collected !== []) {
    file_put_contents($jsonPath, json_encode($collected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    printf("\nwrote %d results to %s\n", count($collected), $jsonPath);
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

/**
 * @return array{reply: string, state: string, chars: int, sentences: int, accepted: bool}
 */
function report(string $raw, ?string $accepted): array
{
    // The validator repairs malformed trailers, so its output is what the player
    // actually sees; reading the raw content would misreport a repaired reply.
    $source = $accepted ?? $raw;

    $reply = $source;
    $state = '(none)';
    $separator = mb_strpos($source, '[STATE]');
    if ($separator !== false) {
        $reply = trim(mb_substr($source, 0, $separator));
        $state = trim(mb_substr($source, $separator + 7));
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

    return [
        'reply' => $reply,
        'state' => $state,
        'chars' => mb_strlen($reply),
        'sentences' => count($endings[0]),
        'accepted' => $accepted !== null,
    ];
}
