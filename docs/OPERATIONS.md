# Operations

## Hosting topology

- App host: Render (recommended always-on Starter or better before public launch)
- Rate-limit storage: Redis (`REDIS_URL`)
- AI providers: env-configured OpenAI-compatible HTTPS endpoints
- Moderation: OpenAI Moderations API
- Logs: structured JSON on stderr

Health check path: **`/readyz`**

## Deploy

### Docker local prod-like

```bash
docker compose up --build -d
```

API: `http://localhost:8080`

### Render

1. Set all production env vars from [CONFIGURATION.md](CONFIGURATION.md).
2. Confirm `STEAM_APP_ID=4982260`, Steam Web API key, session secret, Redis, AI keys.
3. Set health check to `/readyz`.
4. Deploy via Render dashboard or GitHub deploy hook workflow (`.github/workflows/deploy-render.yml`).
5. Smoke `GET /readyz`, `GET /privacy`, and one authenticated chat from a Steam build.

CI workflow `.github/workflows/ci.yml` runs lint + PHPUnit on pushes/PRs.

GitHub Pages docs deploy is **manual** (`workflow_dispatch` in `.github/workflows/pages.yml`).

## Monitoring signals

Key log events:

| Event | Use |
|---|---|
| `Game chat message processed.` | `timingMs.*` breakdown |
| `AI provider selected for response.` | provider, attempt, fallbackCount, latency, cost |
| `AI provider returned error status.` / `AI provider request failed.` | fallback diagnosis |
| `Content safety decision.` | moderation stage/decision/latency |
| `Steam session token issued.` | auth timing breakdown |
| `Run telemetry.` | ending balance aggregation |

Interpretation tips:

- high `timingMs.quotas` → Redis / limiter
- high `timingMs.steamVerify` → Steam Web API / network
- high provider `latencyMs` → inference or provider cold start
- `attempt > 1` / `fallbackCount > 0` → fallback cost/latency
- large gap vs Unreal `DurationMs` → DNS/TLS/proxy/cold start between client and backend

Never expect chat bodies, tickets, or tokens in logs.

## Incident runbook

### Redis down / `/readyz` failing

1. Check Redis service and `REDIS_URL`.
2. Do not bypass prod readiness checks.
3. Restore Redis before sending players to chat-heavy builds.

### Global or player quota exceeded

1. Confirm whether abuse or real launch traffic.
2. Raise `GAME_GLOBAL_DAILY_QUOTA` / player quotas only with cost awareness.
3. Watch AI cost logs after changes.

### AI primary outage

1. Confirm fallbacks enabled and keyed.
2. Watch `fallbackCount` and total latency.
3. If moderation provider is down, expect fail-closed safe replies.

### Auth failures spike

1. Verify `STEAM_WEB_API_KEY` / `STEAM_APP_ID`.
2. Confirm client App ID `4982260` and ticket format.
3. Check Steam API status and auth rate limits.

### High 5xx

1. Pull Render logs filtered by status/path.
2. Check recent deploy diffs.
3. Roll back to previous Render deploy / Steam build if needed.

## Cold start policy

Free-tier cold starts are unacceptable for production Steam traffic. Use an always-on plan before public release. Tracked in the game [`RELEASE_CHECKLIST.md`](../../../Game/Loop9/RELEASE_CHECKLIST.md).

## Rollback

1. Keep previous known-good Render deploy.
2. Keep previous Steam depot/build ready.
3. Prefer reverting backend independently of the game depot when the fault is API-only.
