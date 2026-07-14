# Loop9 Backend

Symfony 8 backend za game chat pipeline sa AI provider fallback logikom, rate limiting-om i JSON API endpoint-om.

## Architecture

Hexagonal / DDD-lite layout:

- `src/Domain` — value objects, ports, pure policies/validators (no Symfony/HTTP)
- `src/Application` — use-case handlers (`SendChatMessageHandler`)
- `src/Infrastructure` — AI providers (HttpClient), HTTP controllers, auth/rate-limit adapters
- `src/Shared` — cross-cutting HTTP subscribers (CORS, JSON errors)
- `config/prompts` — externalized system prompts

## Stack

- PHP 8.4+
- Symfony 8
- Monolog
- Symfony Rate Limiter / HttpClient
- PHPUnit
- Docker + Docker Compose
- GitHub Actions (CI + Pages deploy)

## API

### POST /api/chat

Headeri:

- `Content-Type: application/json`
- `X-Game-Token: <GAME_API_TOKEN>`
- `X-Player-Id: <player_id>` (alternativno `player_id` u body)

Primer zahteva:

```json
{
  "message": "Da li je monitor opet pomeren?",
  "player_id": "player-42",
  "language": "sr",
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

`state` matches the Unreal `Loop9BackendChatService` payload (`kindness`/`suspicion` as discrete `-1|0|1`).

Primer odgovora:

```json
{
  "role": "assistant",
  "message": "Slusaj me, ne gubi vreme, proveri vrata i uzmi lit elevator.[STATE]KINDNESS=0;SUSPICION=1",
  "createdAt": "2026-04-23T14:21:17+00:00"
}
```

## Lokalno pokretanje (bez Dockera)

1. Instaliraj zavisnosti:

```bash
composer install
```

2. Pokreni server:

```bash
symfony server:start
```

Alternativno:

```bash
php -S 127.0.0.1:8000 -t public
```

3. Osnovna provera:

```bash
php bin/console lint:container
php bin/console lint:yaml config
composer test
```

## Docker

### Build i run

```bash
docker compose up --build -d
```

API ce biti dostupan na:

- `http://localhost:8080/api/chat`

### Stop

```bash
docker compose down
```

## CI/CD

U projektu postoje 2 workflow-a:

- `.github/workflows/ci.yml`
  - Run na svaki push i pull request
  - `composer install`
  - `lint:container`
  - `lint:yaml`

- `.github/workflows/pages.yml`
  - Run na svaki push na `main`
  - Build staticke dokumentacije iz `docs/`
  - Deploy na GitHub Pages

- `.github/workflows/deploy-render.yml`
  - Run na svaki push na `main` (ili manual `workflow_dispatch`)
  - Pokrece lint korake (`lint:container`, `lint:yaml`)
  - Ako sve prodje, okida Render deploy hook

## Aktivacija GitHub Pages

1. Pushuj fajlove na GitHub repo.
2. U repou otvori Settings > Pages.
3. U "Build and deployment" izaberi "GitHub Actions".
4. Svaki push na `main` ce automatski odraditi deploy.

Nakon deploy-a, dokumentacija je dostupna na:

- `https://<username>.github.io/<repo>/`

## Backend auto deploy na Render

GitHub Pages ostaje za dokumentaciju (`docs/`), dok backend ide na Render.

1. Na Render-u kreiraj Web Service iz ovog GitHub repoa.
2. U Render-u otvori service settings i kopiraj **Deploy Hook URL**.
3. U GitHub repou dodaj secret:
   - `RENDER_DEPLOY_HOOK_URL` = Render deploy hook URL
4. Push na `main` ce automatski:
   - pokrenuti verifikaciju
   - okinuti deploy na Render
5. U Unreal/igri postavi endpoint na Render URL:
   - `https://<tvoj-render-servis>.onrender.com/api/chat`
6. Na Render-u kreiraj i **Key Value** (Redis) instancu i u Web Service env postavi:
   - `REDIS_URL` = internal Redis URL sa Render-a
   - `TRUSTED_PROXIES` = `127.0.0.1,REMOTE_ADDR` (Render proxy prosleđuje pravi IP kroz `X-Forwarded-For`)
   - `APP_ENV` = `prod`

U `prod` okruženju rate limiter brojači žive u Redis-u, pa kvote prežive restart/deploy
i rade ispravno i sa više instanci. U `dev`/`test` Redis nije potreban (filesystem/in-memory).

## Environment promenljive

Najvaznije:

- `GAME_API_TOKEN`
- `GAME_DAILY_PLAYER_QUOTA` (default 120) — poruka po `player_id` dnevno
- `GAME_MONTHLY_PLAYER_QUOTA` (default 2000) — poruka po `player_id` mesečno (~30 dana)
- `GAME_DAILY_IP_QUOTA` (default 300) — plafon po IP (sprečava rotaciju `player_id`)
- `GAME_GLOBAL_DAILY_QUOTA` (default 20000) — ukupan dnevni plafon za ceo servis (kill-switch za AI trošak)
- `REDIS_URL` — Redis DSN za rate limiter storage (obavezno u `prod`)
- `TRUSTED_PROXIES` — proxy-ji kojima se veruje za `X-Forwarded-For` (na Render-u: `127.0.0.1,REMOTE_ADDR`)
- `AI_CHAT_COMPLETIONS_URL`
- `AI_API_KEY`
- `AI_MODEL`
- `AI_FALLBACK_ENABLED`
- `AI_FALLBACK2_ENABLED`

Za Docker mozes koristiti `.env` ili shell environment pri pokretanju compose-a.
`docker compose` sada podiže i lokalni `redis` servis (app default `REDIS_URL=redis://redis:6379`).
