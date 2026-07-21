---
name: queues
description: Queue, job, and scheduler standards for this Laravel app — Horizon on the Redis queue driver, tenant-aware jobs, retries/backoff, failed-job handling, and the scheduler. Use when offloading slow/external work, writing jobs, or configuring workers/scheduler.
---

# Queues, Jobs & Scheduler

## Purpose

Move slow and external work off the request cycle and run it reliably on
**Laravel Horizon** over the **Redis** queue driver. This skill defines how jobs
are designed, retried, and monitored, and how the scheduler is used. The root
`backend` skill's tenancy rules override anything here — the defining trap is a
job running without a tenant context.

## Scope

Queue jobs (`app/Jobs/`), queued listeners, worker operation via Horizon, failed-job
handling, and the scheduler (`routes/console.php`). The queue driver is **Redis, not
database**; workers are managed by **Horizon** (`app/Providers/HorizonServiceProvider.php`;
`config/horizon.php:203` pins `connection => redis`). Complements `performance` (why to
queue), `devops` (worker deployment), `stripe` (webhook reconciliation).

## Responsibilities

- Queue anything slow or external: outbound webhooks, stats rollups, bulk work.
- Re-initialize tenancy inside every tenant-scoped job — a queued job has **no ambient tenant**.
- Make jobs idempotent and retry-safe; handle and monitor failures.
- Schedule recurring work through the scheduler with overlap/one-server guards.

## Best Practices

- **Tenancy is not ambient in a job.** A queued job runs outside the request, so there
  is no active tenant. Carry the tenant **id** and re-enter with `$tenant->run(fn () => …)`
  — see `app/Jobs/DeliverWebhook.php:57` and `app/Jobs/RefreshTenantStats.php:37`. A tenant
  query outside `run()` hits the wrong connection (a cross-tenant leak, the worst bug here).
- **Pass ids, not models.** Both real jobs take a `string $tenantId` and re-fetch, tolerating
  deletion between dispatch and execution (`DeliverWebhook.php:51`, `RefreshTenantStats.php:37`).
- **Redis-backed cache must be taggable.** `TenancyServiceProvider::assertCacheStoreSupportsTagging`
  (`app/Providers/TenancyServiceProvider.php:133`) throws at boot on a non-taggable store
  (`file`/`database`); tags are what scope cache per tenant. Jobs touching cache rely on this.
- **Retries & backoff.** Set `$tries`/`$backoff` explicitly; `DeliverWebhook` uses
  `$tries = 3`, `$backoff = [10, 60, 300]` (`DeliverWebhook.php:34`) and re-throws to let the
  queue retry until attempts are exhausted, then fails quietly (already recorded).
- **Idempotent jobs.** A retry or duplicate dispatch must be safe — check state before acting,
  upsert by id. Retries *will* happen.
- **Scheduler in `routes/console.php`.** Recurring work is defined there, not ad-hoc cron.
  `app:refresh-org-stats` runs `hourly()->withoutOverlapping()->onOneServer()`
  (`routes/console.php:17`) and fans out one `RefreshTenantStats` job per tenant rather than
  walking every org in one long process.
- **Queued listeners.** `HandleStripeWebhook` and `ForgetCachedPermissions` run as listeners;
  keep them thin and tenant-aware where they touch tenant data.

## Coding Standards

- **Aspire to:** jobs are `ShouldQueue`, thin, delegate business logic to a Service, and define
  `failed()`. This is the standard to aim for — note it is not yet universal: `DeliverWebhook`
  currently holds its delivery logic inline and defines **no `failed()`** method. `RefreshTenantStats`
  already delegates (to `App\Services\Admin\RefreshOrganizationStats`). Follow the aspirational
  shape for new jobs.
- Explicit `$tries`/`$backoff`/`$timeout`; no infinite retries.
- Queue/connection names via config, not hardcoded.

## Performance Guidelines

- Fan out per-tenant work into many small jobs (as the stats refresh does) instead of one giant run.
- Keep jobs short; chain multi-step work.
- **Monitor via Horizon** (`/horizon`, gated on `is_super_admin` in `HorizonServiceProvider`) —
  queue depth, throughput, failed jobs, and retries. There is **no Pulse and no Sentry** here.

## Security Considerations

- Don't serialize secrets into job payloads (they sit in Redis); fetch from config at runtime.
- Validate/authorize **before** dispatch — jobs run without the HTTP auth context.
- Re-check tenant membership/state inside the job; never widen a query across tenants.
- Scrub PII/secrets from job logs and failure records.

## Common Mistakes

- Querying tenant data in a job without `$tenant->run()` → wrong-connection / cross-tenant read.
- Passing whole models → stale/large payloads; pass the id.
- Assuming the database queue driver — it's **Redis**; workers are **Horizon**.
- Booting on a non-taggable cache store (`file`/`database`) → the app refuses to boot.
- Missing `failed()` on a new job → silent data loss (`DeliverWebhook` is the example not to copy).
- Scheduler tasks without `withoutOverlapping()`/`onOneServer()` → double runs.

## Recommended Workflow

1. Identify the slow/external work; create a thin `ShouldQueue` job carrying the tenant id.
2. Re-enter tenancy with `$tenant->run(...)`; make the body idempotent; set `$tries`/`$backoff`/`$timeout`.
3. Delegate logic to a Service and add `failed()` (the standard for new jobs).
4. For recurring work, add a guarded entry in `routes/console.php`.
5. Test with `Queue::fake()`/`Bus::fake()` and by running the job inside a tenant; assert idempotency.
6. On deploy, `php artisan horizon:terminate` so workers pick up new code (see `devops`).

## Output Expectations

Slow/external work runs in idempotent, tenant-aware queued jobs on Redis, with explicit
retry/backoff and (for new jobs) `failed()` handling; recurring work uses the guarded
scheduler in `routes/console.php`; no secrets in payloads. Workers are Horizon; monitoring
is Horizon/Telescope. Files referenced as `path:line`.
