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
    "anomaly_key": ""
  },
  "anomaly_context": "flicker in hallway"
}
```

Notes:

- With session auth, client `player_id` is ignored; identity comes from the token.
- `state.kindness` / `state.suspicion` are discrete `-1|0|1` values from the Unreal client.
- `loop_index` shapes urgency and provider/token policy.

Response `200`:

```json
{
  "role": "assistant",
  "message": "Check the hallway light, then take the lit elevator.[STATE]KINDNESS=0;SUSPICION=0",
  "createdAt": "2026-04-23T14:21:17+00:00"
}
```

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
  "build": "1.0.0"
}
```

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
