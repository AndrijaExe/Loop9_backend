<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Domain\Chat\RuntimeContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class ChatRequestMapper
{
    public const MAX_MESSAGE_LENGTH = 4000;
    public const MAX_LANGUAGE_LENGTH = 32;
    public const MAX_ANOMALY_CONTEXT_LENGTH = 1000;
    public const MAX_JSON_DEPTH = 8;

    /**
     * @return array{message: string, context: RuntimeContext, payload: array<string, mixed>}
     */
    public function map(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent(), true, self::MAX_JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }

        if (!is_array($payload)) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }

        $playerMessage = $payload['message'] ?? null;

        if (!is_string($playerMessage)) {
            throw new BadRequestHttpException('Field "message" must be a string.');
        }

        if (trim($playerMessage) === '') {
            throw new BadRequestHttpException('Message cannot be empty.');
        }

        if (mb_strlen($playerMessage) > self::MAX_MESSAGE_LENGTH) {
            throw new BadRequestHttpException(sprintf(
                'Field "message" must be at most %d characters.',
                self::MAX_MESSAGE_LENGTH
            ));
        }

        if (isset($payload['language']) && is_string($payload['language'])
            && mb_strlen($payload['language']) > self::MAX_LANGUAGE_LENGTH) {
            throw new BadRequestHttpException(sprintf(
                'Field "language" must be at most %d characters.',
                self::MAX_LANGUAGE_LENGTH
            ));
        }

        if (isset($payload['anomaly_context']) && is_string($payload['anomaly_context'])
            && mb_strlen($payload['anomaly_context']) > self::MAX_ANOMALY_CONTEXT_LENGTH) {
            throw new BadRequestHttpException(sprintf(
                'Field "anomaly_context" must be at most %d characters.',
                self::MAX_ANOMALY_CONTEXT_LENGTH
            ));
        }

        return [
            'message' => $playerMessage,
            'context' => RuntimeContext::fromArray([
                'language' => $payload['language'] ?? null,
                'ai_stability' => $payload['ai_stability'] ?? null,
                'state' => $payload['state'] ?? null,
                'anomaly_context' => $payload['anomaly_context'] ?? null,
                'loop_index' => $payload['loop_index'] ?? null,
                'offtopic' => $payload['offtopic'] ?? null,
            ]),
            'payload' => $payload,
        ];
    }
}
