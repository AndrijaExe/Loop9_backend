# Configuration

Defaults live in [`.env`](../.env). Production secrets belong in the host environment (Render), never in git.

## Environment variables

### App / platform

| Variable | Default / notes |
|---|---|
| `APP_ENV` | `dev` locally; `prod` on Render |
| `APP_SECRET` | Symfony secret |
| `DEFAULT_URI` | CLI URL generation |
| `TRUSTED_PROXIES` | Required in prod. Render example: `127.0.0.1,REMOTE_ADDR` |
| `REDIS_URL` | Required in prod for rate-limit storage, event counters and player presence. Unset means counts live for one request, so `GET /metrics` answers with nothing in it |
| `METRICS_TOKEN` | Shared secret for `GET /metrics`. Empty means the endpoint returns 404 |

### Auth

| Variable | Default / notes |
|---|---|
| `STEAM_WEB_API_KEY` | Publisher Web API key |
| `STEAM_APP_ID` | `4982260` for Loop 9 |
| `SESSION_TOKEN_SECRET` | HMAC secret; ≥32 chars in prod |
| `SESSION_TOKEN_TTL` | `43200` seconds (12h) |
| `AUTH_ALLOW_GAME_TOKEN` | Must be `false` in prod |
| `GAME_API_TOKEN` | Legacy shared token for non-prod only |

### Quotas

| Variable | Default |
|---|---|
| `GAME_DAILY_PLAYER_QUOTA` | `120` |
| `GAME_MONTHLY_PLAYER_QUOTA` | `2000` |
| `GAME_DAILY_IP_QUOTA` | `300` |
| `GAME_GLOBAL_DAILY_QUOTA` | `5000` |
| `GAME_ABUSE_WATCH_CHATS` | `40` — a player who sends this many chats in one UTC day is counted as hot. Does not refuse the request; the quotas still do |

Burst chat limit (`20/min`) and Steam auth (`10/min`) / telemetry (`30/h`) are configured in `config/packages/rate_limiter.yaml`.

### AI primary

| Variable | Notes |
|---|---|
| `AI_CHAT_COMPLETIONS_URL` | OpenAI-compatible chat completions URL |
| `AI_API_KEY` | Required in prod |
| `AI_MODEL` | Model id used for routing/cost logs. This is the `best` tier, so loops 4+ open on it: `gpt-5.6-terra` |
| `AI_SYSTEM_PROMPT` | Optional override; otherwise files in `config/prompts` |
| `AI_TLS_VERIFY` | Default `true` |
| `AI_COMMITMENT_ENABLED` | Default `false`. Enables per-run location misdirection and late wrong-lift. Keep off until Steam QA and live probe of the commitment path pass. |

### AI fallbacks

| Variable | Notes |
|---|---|
| `AI_FALLBACK_ENABLED` | `true` to enable first fallback |
| `AI_FALLBACK_CHAT_COMPLETIONS_URL` | |
| `AI_FALLBACK_API_KEY` | |
| `AI_FALLBACK_MODEL` | |
| `AI_FALLBACK_TLS_VERIFY` | |
| `AI_FALLBACK2_ENABLED` | Second fallback |
| `AI_FALLBACK2_*` | Same shape as first fallback |

Fallback2 is the `cheap` tier, so loops 1–3 open on it before anything else: set
`AI_FALLBACK2_MODEL=gpt-5.6-luna`. Leaving its key empty silently drops the tier
and sends every loop to the primary, which is easy to miss because nothing errors.

Vendor mapping is env-driven. Privacy/store copy currently describe Groq primary + OpenAI fallback/moderation; set URLs/keys accordingly.

### Moderation

| Variable | Default / notes |
|---|---|
| `AI_MODERATION_URL` | `https://api.openai.com/v1/moderations` |
| `AI_MODERATION_API_KEY` | Falls back to OpenAI fallback key if empty |
| `AI_MODERATION_MODEL` | `omni-moderation-latest` |
| `AI_MODERATION_TIMEOUT_SECONDS` | `3` (allowed range 1–10) |

## Production readiness

`ProductionConfigValidator` + `/readyz` fail closed when production is missing required security/AI/Redis settings, including:

- trusted proxies
- Redis URL
- session secret strength/TTL sanity
- Steam key + app id
- `AUTH_ALLOW_GAME_TOKEN=false`
- primary AI HTTPS URL/key/model
- moderation config
- TLS verify expectations

## Docker / Compose

- `Dockerfile` builds the Apache production image with OPcache and the 64 KiB body limit.
- `docker-compose.yml` passes env through for a prod-like local run on port `8080`.

See [OPERATIONS.md](OPERATIONS.md) and [DEVELOPMENT_AND_TESTING.md](DEVELOPMENT_AND_TESTING.md).
