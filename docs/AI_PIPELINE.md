# AI Pipeline

## Prompt selection

| Prompt file | When |
|---|---|
| `config/prompts/system_compact.txt` | Early loops (1–3) |
| `config/prompts/system_full.txt` | Later loops (4+) |

`PromptFactory` also injects runtime context:

- loop index (for urgency only; model must not reveal the number)
- anomaly labels normalized from Unreal internal names
- relationship tone bands (including moderate/high dependency)
- explicit clean-loop-1 guard against inventing anomalies

## Provider routing

`ProviderRoutingPolicy` + `AiProviderCatalog`:

| Loop band | Order | Max tokens |
|---|---|---|
| 1–3 | cheap → balanced → best | 90 |
| 4–6 | balanced → best → cheap | 130 |
| 7+ | best → balanced → cheap | 180 |

Tiers map to configured primary / fallback / fallback2 providers via env.

## Cascade and deadlines

`OpenAiCompatibleAiChatGateway`:

- total AI deadline: **45 seconds**
- per-attempt HTTP timeout defaults around **30 seconds**, clipped by remaining budget
- response body cap: **256 KiB**
- failed/invalid attempts move to the next provider without multiplying spend on clearly invalid payloads

Client chat timeout is **65 seconds**, so network overhead still fits around the backend deadline.

## Output contract

Assistant text must end with:

```text
[STATE]KINDNESS=-1|0|1;SUSPICION=-1|0|1
```

`AssistantReplyFormatValidator` enforces this before the reply is accepted.

## Moderation

Order in `ChatService`:

1. Local safety detector (PII / copyright-request style checks)
2. OpenAI Moderations API on input
3. AI generation
4. OpenAI Moderations API on output

Fail-closed: if moderation is unavailable, the request is blocked and replaced with a localized in-fiction fallback from `SafeChatFallbackFactory` (EN/SR/DE/FR/RU).

Message content is never written to logs. Only decision metadata and latency are logged (`Content safety decision.`).

## Cost logging

`CostEstimator` estimates USD cost from model id + token usage for structured logs (`AI provider selected for response.`). Keep model ids aligned with configured providers when changing routing.

## Prompt change process

1. Edit `system_compact.txt` and/or `system_full.txt`.
2. Update `PromptFactory` only if runtime injection rules change.
3. Extend/adjust `PromptFactoryTest` and related domain tests.
4. Run `composer test`.
5. Spot-check clean loop 1, one anomaly context, and tone variants in staging.
6. Do not duplicate prompt text into game docs; link here instead.
