# API Reference

Base content type for JSON endpoints: `application/json`.

Cross-cutting:

| Concern | Behavior |
|---|---|
| CORS | Enabled for `/api/*` |
| Body limit | 64 KiB |
| AI response cap | 256 KiB transport-side |
| Correlation | Optional/validated `X-Request-Id` (16–64 hex); echoed on responses when present |
| Error envelope | `{ "error": { "message": "...", "code": "..." } }` for `/api/*` |

## Endpoints

### `GET /healthz`

Liveness only.

```json
{ "status": "ok" }
```

### `GET /readyz`

Readiness. In production this validates config and Redis write access.

- `200` `{ "status": "ready" }`
- `503` `{ "status": "not_ready" }`

### `GET /metrics`

Event counts and current levels for an external monitor. Requires `X-Metrics-Token`.

```json
{
  "counters": { "chat.messages": 1842, "ai.fallback": 3, "ai.tokens.in": 184200, "ai.tokens.out": 92100, "ai.cost.micros": 140, "ai.tokens.in.openai": 184200, "ai.tokens.out.openai": 92100, "ai.cost.micros.openai": 140 },
  "gauges": { "players.online": 4, "players.day": 61, "abuse.chats.heaviest": 12, "abuse.players.hot": 0 },
  "storage": "redis",
  "at": "2026-08-13T09:00:00+00:00"
}
```

- `404` when `METRICS_TOKEN` is unset — the endpoint is not published on this instance
- `403` on a wrong or missing token
- `503` when the counter store is unreachable

Token and cost totals are also published per billed host (`ai.tokens.in.openai`, …) so a monitor
can split spend without holding a provider key. The host is taken from the URL we called, not
from our env labels (`primary` / `fallback1`).

`counters` never reset, so an interval is the difference between two readings. `gauges` are true
only at `at` and mean nothing added up: `players.online` counts distinct players active in the
last five minutes, `players.day` distinct players in the last 24 hours, `abuse.chats.heaviest`
is today's highest single-player chat count, and `abuse.players.hot` is how many players have
crossed the daily watch line.

`storage` is `redis` or `memory`. Without `REDIS_URL` the counts live for the length of one
request, so every reading comes back empty; the field says so rather than leaving a reader to
conclude the game is dead. See [OPERATIONS.md](OPERATIONS.md#event-counters).

Use this as the Render health check path.

### `GET /privacy`

Public HTML privacy policy for Steam store Basic Info.

### `POST /api/auth/steam`

Exchange a Steam auth session ticket for a short-lived backend session token.

Rate limit: 10 / minute / IP.

Request:

```json
{ "ticket": "<hex-encoded steam session ticket>" }
```

Response `200`:

```json
{
  "token": "v1....",
  "expires_at": 1789000000,
  "player_id": "steam-76561198000000001"
}
```

Common failures:

| Status | Code | Meaning |
|---|---|---|
| 429 | `RATE_LIMITED` | Too many auth attempts |
| 400/401/503 | `REQUEST_ERROR` / service errors | Invalid ticket, Steam failure, or missing Steam/session config |

`OPTIONS` returns `204`.

### `POST /api/chat`

Headers:

- `X-Session-Token: <token>` preferred
- or legacy `X-Game-Token` + `X-Player-Id` when explicitly enabled outside production

Request body (canonical fields):

```json
{
  "message": "Is the monitor moved again?",
  "player_id": "player-42",
  "language": "en",
  "loop_index": 3,
  "ai_stability": 0.8,
  "offtopic": false,
  "state": {
    "kindness": 1,
    "suspicion": 0,
    "dependency": 0.2,
    "player_confidence": 0.8,
    "repeat_anomaly": false,
    "anomaly_key": "LightFlickerAnomaly"
  },
  "anomaly_context": "flicker in hallway",
  "anomaly_detail": {
    "zone": "the north corridor",
    "object": "a ceiling light panel"
  },
  "decoy_zone": "the archive room",
  "advice_state": {
    "location_misdirection_used": false,
    "contradiction_exposed": false,
    "pending_decision_surrender": false,
    "wrong_lift_used": false,
    "followed_last_lift_advice": false,
    "visited_suggested_decoy": false,
    "confrontation_response_used": false,
    "last_advice_mode": "none",
    "last_lift_advice": "none"
  },
  "observation_snapshot": {
    "current_zone": "archive",
    "seconds_on_floor": 42,
    "events": [
      {
        "type": "object_inspected",
        "zone": "archive",
        "subject": "office_chair",
        "count": 1,
        "age_seconds": 3
      }
    ],
    "visited_zones": ["lobby", "archive"],
    "run_summary": {
      "floors_started": 3,
      "ai_interactions": 2,
      "elevator_decisions": 1,
      "correct_decisions": 1
    }
  }
}
```

Notes:

- With session auth, client `player_id` is ignored; identity comes from the token.
- `state.kindness` / `state.suspicion` are discrete `-1|0|1` values from the Unreal client.
- `loop_index` shapes urgency and provider/token policy.
- `state.anomaly_key` is `"none"` (or absent) on a clean floor. Any other value
  means an anomaly is active. The recommendation is pinned to the lit elevator
  only after the player message reports a finding; a bare request to decide
  receives a question or search hint instead of a lift verdict.
- `anomaly_detail` is optional and lets the AI point at the anomaly without
  solving the search: `zone` is a coarse landmark, `object` a category noun, both
  in English and neither an actor name. How much of it reaches the reply depends
  on `state.player_confidence`. Omit the object for placeless anomalies such as a
  phantom chat message; omit the whole field to leave the prompt unchanged.
- `decoy_zone` is one authored inactive place, different from every active zone.
  The backend never invents a room; without a decoy it cannot plant a wrong location.
- `advice_state` is structured per-run memory from the client (never raw chat).
  Deception phases run only while `AI_COMMITMENT_ENABLED=true`.
- `observation_snapshot` is optional and backward compatible. Its encoded JSON
  must be at most 2048 bytes. It carries at most eight recent events and eight
  visited zone IDs. Allowed event types are `zone_entered`, `object_inspected`,
  `door_opened`, `door_closed`, `door_denied`, `flashlight_on`,
  `flashlight_off`, `pursuer_observed`, `pursuer_caught`, and
  `call_completed`; unknown types are discarded. Zone/subject IDs are capped at
  48 characters and normalized to lowercase `a-z`, digits, `_`, or `-`
  slugs (the official client emits at most 32 characters). It must never contain
  raw chat, coordinates, actor names, anomaly keys, commitment IDs, or
  relationship floats.

Response `200`:

```json
{
  "role": "assistant",
  "message": "Check the hallway light, then take the lit elevator.[STATE]KINDNESS=0;SUSPICION=0;DEPENDENCY=0",
  "createdAt": "2026-04-23T14:21:17+00:00",
  "advice": {
    "mode": "accurate_hint",
    "lift": "lit",
    "suggested_zone": "the north corridor",
    "commitment_id": "a1b2c3d4e5f60718"
  }
}
```

`advice` is optional for older clients. When present it is **server-authored** from
`AdvicePolicy` (not NLP over the reply text). Modes: `withhold`, `accurate_hint`,
`accurate_lift`, `misdirect_location`, `confrontation`, `wrong_lift`. `lift` is
`none|lit|dark`.

Assistant messages must include a trailing state tag consumed by the game client.

Quota / rate codes:

| Code | Meaning |
|---|---|
| `RATE_LIMITED` | Burst limit |
| `IP_DAILY_QUOTA_EXCEEDED` | IP daily ceiling |
| `PLAYER_DAILY_QUOTA_EXCEEDED` | Player daily ceiling |
| `PLAYER_MONTHLY_QUOTA_EXCEEDED` | Player monthly ceiling |
| `GLOBAL_DAILY_QUOTA_EXCEEDED` | Global daily AI kill-switch |

`OPTIONS` returns `204`.

### `POST /api/telemetry/run`

Anonymous run-finished telemetry. Auth same as chat. Rate limit: 30 / hour / IP.

Request:

```json
{
  "ending": "paranoid_survivor",
  "resets": 4,
  "ai_messages": 12,
  "build": "1.0.0",
  "location_misdirection_used": true,
  "visited_suggested_decoy": true,
  "contradiction_exposed": true,
  "decoy_visit_seconds": 8.5,
  "lift_advice_count": 3,
  "followed_lift_advice_count": 2,
  "wrong_lift_advice_count": 1,
  "followed_wrong_lift_advice_count": 1
}
```

Commitment fields are optional for older clients and default to false/zero.
They are run-level aggregates only—no chat, coordinates, route, or zone name.

Allowed `ending` values:

- `escape_together`
- `obedient_fool`
- `cold_betrayal`
- `merged_memory`
- `the_replacement`
- `paranoid_survivor`

Response: `204 No Content`. No database write; emits structured log event `Run telemetry.`

## Client timeouts

| Call | Client timeout | Backend note |
|---|---|---|
| Steam auth | 15s | Steam verify + token issue |
| Chat | 65s | AI cascade deadline 45s |
| Telemetry | 15s | Log only |

## Source of truth

Route attributes on:

- `SteamAuthController`
- `ChatController`
- `RunTelemetryController`
- `HealthController`
- `ReadyController`
- `PrivacyController`
