# Deployment

How to run this platform in production, and the things that will bite you if you don't.

> Read [ROADMAP.md](ROADMAP.md#architecture-decisions-worth-knowing) first. The
> architecture decisions table explains *why* several of the requirements below
> are not optional.

---

## Topology

```
            ┌────────────┐
   CDN ──── │  React SPA │  static build (dist/) — any static host
            └─────┬──────┘
                  │ HTTPS, Bearer token + X-Organization
            ┌─────▼──────┐        ┌───────────┐
   Nginx ── │ Laravel API│ ────── │   Redis   │  cache · queue · Horizon · session
            └─────┬──────┘        └───────────┘
                  │
        ┌─────────▼─────────┐
        │   Central MySQL   │  users, tenants, plans, subscriptions, invitations
        └─────────┬─────────┘
                  │ provisions on signup
   ┌──────────────┼──────────────┐
┌──▼───┐      ┌───▼──┐      ┌────▼─┐
│tenant│      │tenant│  …   │tenant│   one database per organization
└──────┘      └──────┘      └──────┘
```

---

## Hard requirements

These are not preferences. Each one causes a specific, known failure if ignored.

| Requirement | Why — and what breaks without it |
|---|---|
| **Redis (or Memcached) for cache** | Tenancy tags every cache entry with the tenant id; that tagging is the *only* thing stopping one organization reading another's cached values. `file` and `database` cannot tag. The app now refuses to boot on a non-taggable store rather than fail obscurely later. |
| **MySQL user with `CREATE`/`DROP`** | Tenant databases are provisioned at signup. Scope the grant to the `tenant_%` prefix — do **not** grant globally. |
| **`php artisan migrate` *and* `tenants:migrate`** | Central and tenant schemas are separate. A deploy that runs only `migrate` leaves every tenant database stale. |
| **A queue worker** | Webhooks, emails, and notifications are queued. Without a worker they silently never send. |
| **`APP_KEY` set and stable** | Rotating it invalidates every encrypted value and session — see [Encryption key](#encryption-key) below, which two-factor auth raises the stakes on considerably. |
| **HTTPS** | Bearer tokens are sent on every request. Two-factor codes and recovery codes are sent in request bodies; over plain HTTP they are readable in transit, and a TOTP is replayable for the rest of its 30-second step. |

### Encryption key

`APP_KEY` decrypts every two-factor secret and recovery code on the platform.
That changes what a database dump is worth: without the key it is useless for
bypassing 2FA, and with it, every user's second factor is recoverable.

- Store it in a secret manager, never in git, and never in the same backup as the
  database — a dump and a key in one bucket is a dump with no encryption.
- **Rotating it does not re-encrypt anything.** Existing ciphertext becomes
  unreadable, which for 2FA means every enrolled user is locked out of their own
  account with no self-service path back. If you must rotate, decrypt-and-
  re-encrypt with both keys present, or plan a forced 2FA re-enrolment.
- Encryption, not hashing, is deliberate here: the secret is needed in the clear
  on every login, and recovery codes are shown to the user again on request. That
  is a considered trade — it is why the key matters this much.

### PHP extensions

`pdo_mysql`, `mbstring`, `bcmath`, `intl`, `zip`, `gd`, `redis` (phpredis), and
**`pcntl` + `posix`** for Horizon. The latter two do not exist on Windows — the
Docker image installs them, which is why `composer.json` carries platform
overrides so Windows developers can still `composer install`.

---

## Deploy sequence

Order matters. Migrating tenants before central will fail; caching config before
setting env will bake in the wrong values.

```bash
# 1. Code + dependencies (no dev deps in production)
composer install --no-dev --optimize-autoloader

# 2. Central schema first — tenant provisioning reads the tenants table
php artisan migrate --force

# 3. Every tenant database. Safe to re-run; already-applied migrations are skipped.
php artisan tenants:migrate --force

# 4. Plans (idempotent — refreshes limits/copy without duplicating)
php artisan db:seed --class=Database\\Seeders\\PlanSeeder --force

# 5. API docs
php artisan l5-swagger:generate

# 6. Cache config/routes/views — AFTER env is final
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 7. Restart workers so they pick up the new code
php artisan horizon:terminate     # Horizon restarts it gracefully
```

> **`config:cache` and `env()`**: once config is cached, `env()` returns null
> outside config files. Anything reading env at runtime silently breaks in
> production while working perfectly in development. Read from `config()` instead
> — this already bit the Stripe price ids once (see CHANGELOG).

### Zero-downtime note

`tenants:migrate` walks every tenant database serially. With many tenants this is
slow and runs while the old code is still serving. Keep tenant migrations
**backwards-compatible** (add columns, don't rename or drop in the same release)
so both code versions can run against either schema.

---

## Environment

Start from [`backend/.env.example`](../backend/.env.example). The values that
differ from development:

```env
APP_ENV=production
APP_DEBUG=false                 # never true in production — leaks internals
APP_URL=https://api.example.com
FRONTEND_URL=https://app.example.com

DB_HOST=<central mysql host>
DB_DATABASE=saas_central
TENANT_DB_PREFIX=tenant_

CACHE_STORE=redis               # MUST support tagging (see above)
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis

TELESCOPE_ENABLED=false         # heavy; enable only to debug
L5_SWAGGER_GENERATE_ALWAYS=false

STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_STARTER_MONTHLY=price_...
# …one per plan/interval; without them a plan cannot be subscribed to.
```

The frontend needs `VITE_API_URL` at **build** time — Vite inlines it, so it
cannot be changed after `npm run build` without rebuilding.

```bash
cd frontend
VITE_API_URL=https://api.example.com npm run build   # → dist/
```

---

## Stripe

1. Create products/prices in Stripe, then set the `STRIPE_PRICE_*` env vars to
   the **price ids** (not product ids). Test and live mode have different ids.
2. Point a webhook at `POST /api/v1/billing/webhook` and set
   `STRIPE_WEBHOOK_SECRET`. This route is deliberately outside auth and CSRF —
   Stripe signs the payload instead.
3. Subscribe to at least: `customer.subscription.updated`,
   `customer.subscription.deleted`, `invoice.payment_succeeded`,
   `invoice.payment_failed`.

Without the webhook, renewals, dunning, and dashboard-side plan changes never
reach the app, and local subscription state drifts from Stripe's.

> ⚠️ The subscribe/swap/invoice paths are written to Cashier's contract but have
> **never been exercised against live Stripe** — no API keys were available. Run
> a full test-mode subscription before trusting them with real money.

---

## Processes to run

| Process | Command | Notes |
|---|---|---|
| Web | `php-fpm` behind Nginx | see [`docker/nginx/default.conf`](../docker/nginx/default.conf) |
| Queue | `php artisan horizon` | supervise it; restart on deploy |
| Scheduler | `* * * * * php artisan schedule:run` | one cron entry |

Horizon config lives in `config/horizon.php`. It needs `pcntl`/`posix`.

---

## Storage

Uploads go to a **tenant-suffixed disk** (`FilesystemTenancyBootstrapper`), so
one organization's files are never served from another's directory. For S3, set
`FILESYSTEM_DISK=s3` and the `AWS_*` vars; the suffixing applies the same way.

`php artisan storage:link` if serving local uploads.

---

## Backups

Database-per-tenant makes this both easier and easier to get wrong.

- Back up the **central** database *and* **every** `tenant_*` database. A central-only
  backup restores an empty product: it knows the organizations exist but holds
  none of their data.
- Per-tenant restore is a genuine advantage — one organization can be rolled back
  without touching others.
- Back up **uploaded files** alongside; a database restore without the matching
  files leaves rows pointing at nothing.

---

## Production checklist

Before the first real customer:

**Security**
- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] HTTPS enforced; HSTS set
- [ ] `APP_KEY` set, stored in a secret manager, never in git — and **not** in the same backup as the database (it decrypts every 2FA secret)
- [ ] MySQL app user's `CREATE`/`DROP` scoped to the `tenant_%` prefix
- [ ] MySQL durability left alone — CI turns `innodb_flush_log_at_trx_commit`/`sync_binlog` off for its throwaway container; never do that to a server holding real data
- [ ] `TELESCOPE_ENABLED=false` (or route-gated to admins)
- [ ] Horizon dashboard gated — see `HorizonServiceProvider::gate()`
- [ ] Review the upload deny-list in `AttachmentController`/`FileManagerService`

**Correctness**
- [ ] `migrate` **and** `tenants:migrate` both run
- [ ] `PlanSeeder` run; `STRIPE_PRICE_*` set for every plan you sell
- [ ] Stripe webhook registered and secret set
- [ ] A test-mode subscribe → swap → cancel → invoice cycle actually performed
- [ ] Cache store supports tagging (the app asserts this at boot)

**Operations**
- [ ] Queue worker supervised and restarting on deploy
- [ ] Scheduler cron installed
- [ ] Backups covering central + all tenant DBs + uploaded files
- [ ] Restore tested — an untested backup is a hope, not a backup
- [ ] Error tracking wired into `ErrorBoundary` (frontend) and the exception
      handler (backend); both currently only `console.error`/log

**Known gaps** (see [ROADMAP.md](ROADMAP.md))
- [ ] Test coverage never measured — the 80% target is unverified
- [ ] Two-factor auth and social login not implemented
- [ ] Stripe paths unverified against live API
- [ ] OpenAPI documents a subset of endpoints

---

## Docker

[`docker-compose.yml`](../docker-compose.yml) runs the whole stack (backend,
horizon, nginx, frontend, mysql, redis, phpmyadmin) and is intended for
**development**. For production, build the images but supply real env, external
managed MySQL/Redis, and remove phpMyAdmin.

```bash
docker compose up -d
docker compose exec backend php artisan migrate --force
docker compose exec backend php artisan app:demo   # local only
```
