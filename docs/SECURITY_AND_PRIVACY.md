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

Global daily quota is the AI cost kill-switch. Crossing `GAME_ABUSE_WATCH_CHATS` (default 40)
in one UTC day does not refuse the player; it increments `abuse.watch` so a monitor can mail
you before they hit the cap. The mark is a hash, the same as presence.

### Order of checks on `POST /api/chat` (audited 08.09.2026)

1. Session token (HMAC, stateless). Production rejects the legacy game token in
   code (`GameTokenAuthenticator`), not just by env. No token → 403, nothing
   below runs.
2. Burst `game_chat` — cheap, keyed on auth scope + real client IP.
3. Body validation (JSON depth, 64 KiB body, message ≤ 1000 chars,
   `anomaly_context` ≤ 1000, `observation_snapshot` ≤ 2048 bytes,
   `run_history` ≤ 12 fields of clamped ints / closed-list labels). Malformed
   requests never touch a quota.
4. IP daily → player daily → player monthly → global daily. Player identity is
   the verified Steam ID; one Steam account is one bucket no matter how many
   tokens it mints.
5. Only now: input moderation → provider cascade → output moderation.

Every limiter is stored in Redis in production (`rate_limiter.storage`), so the
counters are shared across instances and survive deploys; `/readyz` fails if
Redis or `TRUSTED_PROXIES` are missing.

### What an attacker can actually make you pay for

- Without a Steam account that owns the game: nothing. Every AI-spending path
  is behind a Steam-verified session token.
- With one owned copy: at most `GAME_DAILY_PLAYER_QUOTA` (120) chats a day and
  `GAME_MONTHLY_PLAYER_QUOTA` (2000) a month, whatever they do with tokens or IPs.
- Across all accounts combined: at most `GAME_GLOBAL_DAILY_QUOTA` (5000)
  accepted chats a day. One accepted chat is worst case 1 input moderation + up
  to 3 completions (provider failover / one format retry) + 1 output
  moderation, with `max_tokens` 90–180 and a prompt bounded by the caps above.

The quotas count requests, not dollars. The last backstop is a hard monthly
spend limit on the AI provider account itself — set it, it is not something
this repo can enforce.

Known soft spots, none of which open unpaid spend: input moderation fails open
when the moderation API is down; a stolen session token is valid until its TTL
(12 h) and cannot be revoked; `X-Forwarded-For` is only trusted from Render's
own proxy (`TRUSTED_PROXIES=127.0.0.1,REMOTE_ADDR`), so never expose the PHP
port directly.

## Data handling

| Data | Handling |
|---|---|
| Chat messages | Processed in memory for the request; not stored in a database |
| Observation snapshots | Bounded enum events and sanitized authored IDs, processed in memory for the request; no raw chat, coordinates, actor names, anomaly keys, commitment IDs, or relationship floats |
| Steam tickets / session tokens / API keys | Never logged |
| Player ids in logs | Prefer hashed / derived identifiers |
| Telemetry | Structured log only |
| Player presence | Truncated hash of the player id in Redis, scored by last-seen time, dropped after 24h. Read only as a count; the mark names nobody by itself, though anyone holding both the set and a player id could test one against the other |
| Application logs | Short retention on host (about 30 days typical on Render) |

Live policy page: `GET /privacy` (`PrivacyController`). Use that URL in Steamworks Basic Info.

## Transport / request hardening

- 64 KiB request body limit; chat message ≤ 1000 characters (lowered from 4000
  on 08.09.2026 to cap input tokens — the phone chat never needs more)
- HTTPS AI endpoints expected in production
- TLS verification defaults on
- Trusted proxies required so IP quotas see the real client address behind Render
- JSON error responses avoid leaking internal exception details on 5xx

## Content safety

Input and output are checked with OpenAI Moderations plus local PII/copyright detectors. Gameplay tone (insults, in-fiction threats, horror) is allowed through. Illegal / AO sexual / self-harm-instruction categories, and generated group-targeted hate, use the in-fiction fallback. Input fails open if moderation is down; output fails closed.
