# Operations

## Hosting topology

- App host: Render (recommended always-on Starter or better before public launch)
- Rate-limit storage and event counters: Redis (`REDIS_URL`)
- AI providers: env-configured OpenAI-compatible HTTPS endpoints
- Moderation: OpenAI Moderations API (narrow: illegal / AO sexual / self-harm instructions; insults pass)
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

### Event counters

`GET /metrics` (header `X-Metrics-Token: $METRICS_TOKEN`) returns cumulative counts of the same
events, so a watcher can see volume and failure rates without parsing the log stream:

```
chat.messages  chat.denied  chat.denied.<reason>  abuse.watch
api.errors  ai.fallback  ai.failed
ai.tokens.in  ai.tokens.out  ai.cost.micros
ai.tokens.in.<vendor>  ai.tokens.out.<vendor>  ai.cost.micros.<vendor>
safety.blocked  safety.unavailable  auth.issued  auth.rejected
run.ended  run.ended.<ending>
```

`<vendor>` is the billed host (`openai`, `gemini`, `groq`, …), taken from the URL we called.
`primary` and `fallback1` are our labels and never appear here.

The endpoint is a read, not a push. Nothing in this service is scheduled and nothing holds the
monitor's credentials; whoever wants a reading asks for one.

Counters live in one Redis hash (`loop9:events`) and only ever go up, so an interval is the
difference between two readings. Two consequences follow. A total that drops means Redis lost
the key — a flush, an eviction, a new instance — not that the game un-happened. And a reading
without a previous one to compare against says nothing about now; it is the lifetime figure.

The same response carries gauges, which are levels rather than totals:

```
players.online  players.day
abuse.chats.heaviest  abuse.players.hot
```

`chat.denied.<reason>` is `burst`, `ip_daily`, `player_daily`, `player_monthly` or `global`.
`abuse.watch` increments once when a player first crosses `GAME_ABUSE_WATCH_CHATS` in a UTC day.
`abuse.chats.heaviest` is that day's highest single-player count; `abuse.players.hot` is how many
players have crossed the watch line. Marks are hashed, so the numbers say how hard, never who.

Presence is a sorted set (`loop9:presence`) of one opaque mark per player, scored by the second
they were last seen, written on a login, a chat message and a finished run. A set, because the
same player sending four messages is one player; hashed marks, because the count answers how
many and never who. Marks older than a day are dropped when the set is read.

Two levels rather than one because they answer different questions: `players.online` is the last
five minutes, which is "is anybody in there right now", and `players.day` is 24 hours, which is
"did anybody play today" on a game quiet enough that the first is usually zero.

Without `METRICS_TOKEN` the route returns 404. With Redis configured but unreachable it returns
503 rather than zeros, because a zero and an unreachable counter mean opposite things. **Without
`REDIS_URL` at all it returns 200 with nothing in it**: counting falls back to the memory of one
request, which cannot survive to be read. `storage` in the payload names which of the two is
happening, so an empty board can be diagnosed without a shell.

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
