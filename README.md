# Loop9 Backend

Symfony 8 backend for Loop 9 Steam authentication, AI chat, quotas, moderation, and run telemetry.

Steam App ID: **4982260**

## Documentation

- [ARCHITECTURE.md](ARCHITECTURE.md) — layers, pipelines, diagrams
- [docs/API.md](docs/API.md) — endpoints, headers, schemas, error codes
- [docs/CONFIGURATION.md](docs/CONFIGURATION.md) — full environment reference
- [docs/AI_PIPELINE.md](docs/AI_PIPELINE.md) — prompts, routing, moderation, costs
- [docs/SECURITY_AND_PRIVACY.md](docs/SECURITY_AND_PRIVACY.md) — auth, quotas, privacy
- [docs/OPERATIONS.md](docs/OPERATIONS.md) — Render, Redis, monitoring, runbooks
- [docs/DEVELOPMENT_AND_TESTING.md](docs/DEVELOPMENT_AND_TESTING.md) — local setup and tests
- Cross-repo index: [`../../DOCUMENTATION.md`](../../DOCUMENTATION.md)
- Game docs: [`../../Game/Loop9/docs/INDEX.md`](../../Game/Loop9/docs/INDEX.md)

Public Pages landing page: [`docs/index.html`](docs/index.html) (manual workflow deploy).

## Architecture

Hexagonal / DDD-lite layout:

- `src/Domain` — value objects, ports, pure policies/validators
- `src/Application` — use-case handlers (`SendChatMessageHandler`)
- `src/Infrastructure` — HTTP, AI transport, auth, rate limits
- `src/Shared` — CORS and JSON error envelope
- `config/prompts` — externalized system prompts

## Stack

- PHP 8.4+
- Symfony 8
- Monolog
- Symfony Rate Limiter / HttpClient
- Redis (required in production)
- PHPUnit
- Docker + Docker Compose
- GitHub Actions (CI, manual Pages, Render deploy hook)

## Quick API map

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/api/auth/steam` | Steam ticket → session token |
| `POST` | `/api/chat` | AI chat pipeline |
| `POST` | `/api/telemetry/run` | Anonymous run-finished log |
| `GET` | `/healthz` | Liveness |
| `GET` | `/readyz` | Readiness (use as health check) |
| `GET` | `/privacy` | Public privacy policy HTML |

Details and examples: [docs/API.md](docs/API.md).

## Local run

```bash
composer install
symfony server:start
# or: php -S 127.0.0.1:8000 -t public
composer test
```

Docker:

```bash
docker compose up --build -d
```

API: `http://localhost:8080`

## Production essentials

1. Set env vars from [docs/CONFIGURATION.md](docs/CONFIGURATION.md).
2. `AUTH_ALLOW_GAME_TOKEN=false`
3. `STEAM_APP_ID=4982260` + publisher `STEAM_WEB_API_KEY`
4. Strong `SESSION_TOKEN_SECRET` and `REDIS_URL`
5. Health check path `/readyz`
6. Prefer an always-on Render plan before public Steam traffic

## Monitoring

Game and backend share `X-Request-Id`. Key events and interpretation tips are documented in [docs/OPERATIONS.md](docs/OPERATIONS.md). Chat bodies, tickets, tokens, and API keys are never logged.
