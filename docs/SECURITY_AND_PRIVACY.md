# Security and Privacy

## Authentication

### Production Steam flow

1. Game requests a Steam session ticket.
2. `POST /api/auth/steam` verifies the ticket with Steam Web API.
3. Backend issues an HMAC session token (`SessionTokenIssuer`, `v1.<payload>.<sig>`).
4. Chat and telemetry send `X-Session-Token`.
5. Player identity is derived as `steam-<id64>`; client-supplied player ids are ignored.

### Legacy game token

- Header: `X-Game-Token`
- Optional `X-Player-Id` / body `player_id`
- Allowed only when `AUTH_ALLOW_GAME_TOKEN=true`
- `/readyz` rejects this combination when `APP_ENV=prod`

## Rate limiting and abuse controls

| Limiter | Scope | Default |
|---|---|---|
| `auth_steam` | IP | 10 / min |
| `game_chat` | auth scope + IP | 20 / min burst |
| `game_ip_daily` | auth scope + IP | 300 / day |
| `player_daily_quota` | hashed player id | 120 / day |
| `player_monthly_quota` | hashed player id | 2000 / 30 days |
| `game_global_daily` | global | 5000 / day |
| `telemetry_ip` | IP | 30 / hour |

Global daily quota is the AI cost kill-switch.

## Data handling

| Data | Handling |
|---|---|
| Chat messages | Processed in memory for the request; not stored in a database |
| Steam tickets / session tokens / API keys | Never logged |
| Player ids in logs | Prefer hashed / derived identifiers |
| Telemetry | Structured log only |
| Player presence | Truncated hash of the player id in Redis, scored by last-seen time, dropped after 24h. Supports a count, not a lookup |
| Application logs | Short retention on host (about 30 days typical on Render) |

Live policy page: `GET /privacy` (`PrivacyController`). Use that URL in Steamworks Basic Info.

## Transport / request hardening

- 64 KiB request body limit
- HTTPS AI endpoints expected in production
- TLS verification defaults on
- Trusted proxies required so IP quotas see the real client address behind Render
- JSON error responses avoid leaking internal exception details on 5xx

## Content safety

Moderation is fail-closed for both input and output. Unsafe or unavailable moderation yields localized in-fiction fallbacks rather than raw unsafe model text.
