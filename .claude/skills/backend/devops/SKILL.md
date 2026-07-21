---
name: devops
description: Deployment, CI/CD, and release standards for this headless Laravel API — GitHub Actions gates, dual central+tenant migrations, config/route/view cache, Horizon restart, OPcache, and rollback. Use when deploying, changing CI, or troubleshooting a release.
---

# DevOps & Deployment

## Purpose

Ship the headless API safely and repeatably. This skill defines the build,
deploy, and release process — quality gates, the **dual migration** step, cache
management, Horizon restart, and rollback. The root `backend` skill's tenancy
rules override anything here; the migration rule below is the #1 thing to get right.

## Scope

CI/CD (`.github/workflows/`), environment config, deploy steps, caches, migrations,
Horizon workers, and rollback for `backend/`. The backend is **headless JSON** — Vite
asset builds belong to `frontend/`, not the backend deploy. Server/OS specifics are in
`linux-server`; containers in `docker`.

## Responsibilities

- Keep CI green: Pint, PHPStan level 6, Pest.
- Run **both** migration commands on deploy (central + per-tenant).
- Refresh config/route/view caches and restart **Horizon** on deploy.
- Provide a tested rollback path.

## Best Practices

- **CI is `.github/workflows/backend-ci.yml`.** On push/PR touching `backend/**` it runs
  `./vendor/bin/pint --test`, `./vendor/bin/phpstan analyse` (level 6), a central
  `php artisan migrate --force` migration check, then `./vendor/bin/pest --coverage`
  (pcov, MySQL + Redis services, `CACHE_STORE=redis`). Frontend has its own
  `frontend-ci.yml`. Note CI's `migrate` is **central only** — the Pest suite provisions
  tenant databases itself; a real deploy is different (below).
- **Dual migrations on deploy — the #1 rule.** Database-per-tenant means a release must run
  **both**:
  ```bash
  php artisan migrate --force            # central: tenants, users, plans, subscriptions
  php artisan tenants:migrate --force    # every tenant DB, from database/migrations/tenant/
  ```
  Omitting `tenants:migrate` is the classic broken deploy: central succeeds, every tenant
  request then dies on a missing column/table. Tenant migrations are **anonymous classes**
  (named classes can't run per tenant).
- **Restart Horizon, not raw workers.** Workers run under Horizon, so on deploy run
  `php artisan horizon:terminate` (Horizon gracefully restarts and picks up new code) — **not**
  `queue:restart`. Ensure the scheduler (`schedule:run`, driving `routes/console.php`) is installed.
- **Cache in production:** `php artisan config:cache route:cache view:cache event:cache` after
  deploy; clear on config change. Enable OPcache. Once `config:cache` runs, `env()` outside
  `config/` returns null — read config, never `env()` at call sites.
- **Prod deps:** `composer install --no-dev --optimize-autoloader`. Commit `composer.lock`.
- **Environment via `.env`/secret store:** never commit secrets; keep `.env.example` current.
- **Migrations safe on live data:** additive changes + backfill on big tables; avoid long locks.

## Coding Standards

- Deploy scripts are idempotent and version-controlled (no manual server edits).
- Pin PHP 8.3 in CI/prod to match; keep pipeline steps small and named.
- Regenerate the OpenAPI spec (`php artisan l5-swagger:generate`) as part of the release/quality gate.

## Performance Guidelines

- Ship cached config/routes/views with OPcache warm.
- Scale Horizon workers to backlog; watch queue depth in the **Horizon** dashboard.
- The API serves JSON only — there are no backend static assets/CDN concerns (that's `frontend/`).

## Security Considerations

- Secrets in the server/secret manager, never in git or CI logs.
- Least-privilege deploy credentials and DB users; the DB user needs rights to run tenant migrations.
- Enforce HTTPS; keep dependencies patched (`composer audit`).
- Restrict who can trigger production deploys; require green CI + review.

## Common Mistakes

- **Running `migrate` but not `tenants:migrate`** → every tenant breaks after deploy. (#1 mistake.)
- Using `queue:restart` for Horizon-managed workers instead of `horizon:terminate`.
- Forgetting to cache config/routes in prod, or to clear after a config change.
- Building frontend assets in the backend deploy — they belong to `frontend/`.
- Reaching for Pulse/Sentry — monitoring here is **Horizon + Telescope**.
- Secrets committed or printed in CI logs; no rollback plan.

## Recommended Workflow

1. CI runs Pint + PHPStan (level 6) + Pest on the PR; merge only when green and reviewed.
2. Deploy: `composer install --no-dev --optimize-autoloader`.
3. `php artisan migrate --force` **and** `php artisan tenants:migrate --force`.
4. `config:cache route:cache view:cache event:cache`; confirm OPcache.
5. `php artisan horizon:terminate`; verify Horizon and the scheduler are healthy.
6. Smoke-test critical paths; watch Horizon/Telescope. If broken, roll back the release and restore migration state.

## Output Expectations

A repeatable deploy that passes Pint/PHPStan/Pest, runs **both** central and tenant
migrations, refreshes caches, restarts Horizon, and has a verified rollback. No frontend
asset build in the backend path. Monitoring is Horizon/Telescope — not Pulse/Sentry.
Secrets stay out of git/logs; manual steps documented. Files referenced as `path:line`.
