# Development and Testing

## Local setup

```bash
composer install
```

Run the app:

```bash
symfony server:start
# or
php -S 127.0.0.1:8000 -t public
```

Useful checks:

```bash
php bin/console lint:container
php bin/console lint:yaml config
composer test
```

Docker alternative:

```bash
docker compose up --build -d
```

Copy/override secrets in `.env.local` (uncommitted).

## Test suite

Config: `phpunit.dist.xml` · command: `composer test` / `php bin/phpunit`

| Area | Examples |
|---|---|
| Functional HTTP | chat, steam auth, telemetry, privacy |
| Domain | routing policy, game state, reply format, local safety, fallbacks |
| AI infra | gateway, HTTP client, moderation gateway, prompt factory, cost estimator |
| Auth / config | session issuer, Steam verifier, authenticator, production validator, request monitor |

Known gaps:

- no live Redis / Steam / provider integration tests in CI
- quota 429 paths are mostly unit-covered
- `/readyz` prod fixture is not a full live-host test

## Changing contracts safely

1. Update PHP DTOs/controllers/tests first.
2. Update [API.md](API.md) in the same change.
3. Mirror any client payload assumptions in the Unreal chat/auth services.
4. Run `composer test`.
5. Smoke against a local or staging host with a Steam or legacy-token client.

## CI workflows

| Workflow | Trigger | Purpose |
|---|---|---|
| `.github/workflows/ci.yml` | push/PR | lint + PHPUnit |
| `.github/workflows/deploy-render.yml` | configured deploy hook | Render deploy |
| `.github/workflows/pages.yml` | **manual** `workflow_dispatch` | publish `docs/` to GitHub Pages |

## Documentation landing page

`docs/index.html` is the public Pages entry. Keep it aligned with current Steam-auth API docs; detailed markdown lives alongside it in `docs/`.
