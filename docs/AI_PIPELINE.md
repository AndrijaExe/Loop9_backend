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
- the limit of what it knows about the active anomaly: how much of the client's
  `anomaly_detail` it may say is gated on `player_confidence`, and with nothing
  authored it is told to admit it cannot tell rather than invent a location
- the lit/dark pin is added only when the player already reported a sighting or
  that nothing changed. Skip phrasing is not listed; "which elevator", "what
  do I do", "you choose" all withhold the same way so the phone is not a skip
  button
- when the verdict is withheld, the gateway rejects a formatted reply that
  still contains the localized lit/dark elevator name and spends at most one
  fallback attempt, just like format recovery

## Commitment / controlled misdirection

`AdvicePolicy` decides the phase **before** the model runs. `PromptFactory` only
renders the directive. Production runs with `AI_COMMITMENT_ENABLED=true`.

| Mode | When (flag on) |
|---|---|
| `withhold` | No finding / offtopic — no lift name |
| `accurate_hint` / `accurate_lift` | Normal truthful path |
| `misdirect_location` | Once from loop 5+, moderate+ dependency, valid `decoy_zone`, not Pursuer/Phantom |
| `confrontation` | Once after the client confirms the planted contradiction was exposed; defensive response, no new lift/location |
| `wrong_lift` | Once from loop 7+, after location lie + later `SUSPICION=1` + prior surrender + high dependency; forces dark on an active non-Pursuer anomaly |

Gateway checks: withheld replies must not leak a lift name; forced `wrong_lift`
must include the expected localized dark elevator wording (one fallback try).
The JSON response may include an `advice` object (`mode`, `lift`,
`suggested_zone`, `commitment_id`) authored by the server.

Per-run memory lives only in the Unreal client (`FDragojloCommitmentState`);
cleared on `ResetRunState`, never saved to Cloud or backend storage.

`AI_COMMITMENT_ENABLED` is the master switch. Location and wrong-lift phases
also have independent `AI_COMMITMENT_LOCATION_ENABLED` and
`AI_COMMITMENT_WRONG_LIFT_ENABLED` switches. Turning either child switch off
falls back to truthful guidance without changing the client contract.

## Bounded observation context

The optional `observation_snapshot` is parsed into a closed, bounded domain
model. When `AI_OBSERVATION_CONTEXT_ENABLED=true`, `PromptFactory` renders its
canonical compact JSON after the server-authored advice directive and inside
the existing `UNTRUSTED` markers. It describes approximate recent observations,
not a continuous surveillance feed. The model may claim a player action only
when that action appears in the snapshot.

Observation data is narration context only. It cannot override `AdvicePolicy`,
the selected lift/location directive, or anomaly truth. `anomaly_context`
continues to provide policy knowledge, and `AdvicePolicy` does not inspect the
observation snapshot. When the flag is false or the field is absent, prompt
behavior is unchanged.

## Provider routing

`ProviderRoutingPolicy` + `AiProviderCatalog`:

| Loop band | Order | Max tokens |
|---|---|---|
| 1–3 | cheap → balanced → best | 90 |
| 4–6 | balanced → best → cheap | 130 |
| 7+ | best → balanced → cheap | 180 |

Tiers map to configured primary / fallback / fallback2 providers via env: primary
is `best`, fallback1 `balanced`, fallback2 `cheap`. The shipped pair is
`gpt-5.6-terra` as primary and `gpt-5.6-luna` as fallback2 with no balanced tier,
which the table turns into luna for loops 1–3 and terra from loop 4 on, each
covering the other when a reply comes back in a broken format.

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
[STATE]KINDNESS=-1|0|1;SUSPICION=-1|0|1;DEPENDENCY=-1|0|1
```

`AssistantReplyFormatValidator` enforces this before the reply is accepted.

## Moderation

Order in `ChatService`:

1. Local safety detector (email/phone-like PII, copyright-reproduction requests)
2. OpenAI Moderations API on input
3. AI generation
4. OpenAI Moderations API on output

Gameplay tone is allowed: insults, in-fiction threats, and horror violence pass so the model can emit `KINDNESS` / `SUSPICION` deltas. Blocked on both stages: `sexual`, `sexual/minors`, `self-harm/intent`, `self-harm/instructions`, `illicit`, `illicit/violent`. Output also blocks `hate` / `hate/threatening` (Steam + OpenAI: the game must not generate group-targeted hate).

If the Moderations API is down: **input fails open** (chat still works), **output fails closed** (unchecked model text is replaced by `SafeChatFallbackFactory`).

Message content is never written to logs. Only decision metadata and latency are logged (`Content safety decision.`).

## Cost logging

`CostEstimator` estimates USD from the provider's `usage` and the public short-context list
(OpenAI 5.6 after the 2026-07-30 cut). Cached prompt tokens are billed at the cached input
rate when the provider reports them. Keep model ids aligned with configured providers.

## Prompt change process

1. Edit `system_compact.txt` and/or `system_full.txt`.
2. Update `PromptFactory` only if runtime injection rules change.
3. Extend/adjust `PromptFactoryTest` and related domain tests.
4. Run `composer test`.
5. Spot-check clean loop 1, one anomaly context, and tone variants in staging.
6. Do not duplicate prompt text into game docs; link here instead.
