---
name: backend
description: Use when working on the Laravel 13 multi-tenant API in backend/ — controllers, models, services, migrations, policies, tenancy, or Pest tests. Covers the database-per-tenant traps that cause silent cross-tenant data leaks, the required quality gates, and routes to the detailed topic skills under backend/.
---

# Backend (Laravel 13, database-per-tenant)

Work from `backend/`. PHP 8.3+ (Laravel 13 requires ^8.3), MySQL 8, Redis, Sanctum,
Spatie Permission, stancl/tenancy v3, Cashier, Pest.

This file is the **authoritative, project-specific** entry point. Deeper topic
guidance lives in the sibling skill folders — see **Topic skills** at the bottom.
When a topic skill and this file disagree, **this file wins** (topic skills are
general standards; the tenancy rules below are non-negotiable for this codebase).

## The one thing to get right

**Every organization has its own MySQL database.** A central database holds the
tenant registry, users, plans, subscriptions, invitations, and login history.
Everything else — customers, projects, tasks, events, files, notifications,
roles, permissions, activity log — lives in `tenant_<uuid>`.

So the defining bug class here is **a query hitting the wrong connection**. It
does not throw in the obvious case; it silently reads another tenant's rows or
dies with `Table 'saas_central.permissions' doesn't exist` far from the cause.

Laravel's `newRelatedInstance()` copies the *parent's* connection onto any
related model that doesn't declare one. That single behaviour is the root of
most incidents below. Models therefore pin their connection explicitly:

- `App\Models\Concerns\UsesCentralConnection` — central-only models
- `App\Models\Concerns\UsesTenantConnection` — tenant-only models

**When adding a model, pin it.** Not pinning is the bug.

### Five traps already hit (do not re-introduce)

| Trap | Symptom | Fix in place |
|---|---|---|
| Spatie `Role`/`Permission` inherit `User`'s central connection | roles resolve against central; permission checks wrong | custom Role/Permission models with `UsesTenantConnection` |
| Sanctum resolves tokens against the *active* connection | token lookups hit the tenant DB | custom `PersonalAccessToken` pinned central, registered in `AppServiceProvider` |
| `Rule::exists('plans')` runs on the tenant DB | every subscribe/swap 500s | qualify the rule with the central connection |
| Tenancy outlives the request | next request serves the previous tenant's data | `InitializeTenancyForUser::terminate()` calls `tenancy()->end()` |
| Spatie's global `Gate::before` resolves every ability against `permissions` | any Gate check outside a tenant crashes (`/horizon`, `/telescope`) | `permission.register_permission_check_method => false` + tenancy-aware hook in `AppServiceProvider` |

Before "just add a `->where()`", ask **which database is this query on?**

## Request lifecycle

Tenancy is **header-based**, not domain-based: `X-Organization: <org-slug>`.
Auth resolves first (central), then `InitializeTenancyForUser` checks membership
and boots the tenant database.

`SubstituteBindings` is prepended *after* `InitializeTenancyForUser` in
`bootstrap/app.php` — otherwise route-model binding resolves `{customer}`
against central and 404s.

Super Admin is a central `users.is_super_admin` boolean with a `Gate::before`
bypass, not a Spatie role. Spatie `teams` is not used (tenants are UUIDs; teams
assume integer keys).

## Conventions

- Controllers stay thin; behaviour lives in `app/Services/*`.
- Authorize explicitly: `$this->authorize('update', $model)`. **Never
  `authorizeResource()`** — it registers controller middleware, removed in
  Laravel 11+, and is fatal here.
- A plan/quota breach is **402**, not 403: the caller is permitted, the plan is
  not.
- Migrations: central in `database/migrations/`, tenant in
  `database/migrations/tenant/`. Deploys need **both** `migrate` *and*
  `tenants:migrate`. Tenant migrations must be **anonymous classes** — named
  classes (as Spatie ships) cannot run per tenant.
- Cache **must** be taggable (Redis). Tenancy tags entries per tenant, and that
  tagging is the only thing stopping cross-org cache reads. `file`/`array`
  cannot tag; the app refuses to boot on a non-taggable store.
- Add a column with a **new** migration. Editing a migration that has already
  run needs a destructive `migrate:fresh`.

## Tests (Pest)

Run from `backend/`: `./vendor/bin/pest`.

- **Real MySQL, on purpose.** SQLite cannot prove database-per-tenant isolation.
  171 tests take ~47 minutes because each provisions a real database (~118 DDL
  statements). That cost is the point; do not "fix" it by faking the database.
- `DatabaseTruncation`, **not** `RefreshDatabase`: `CREATE DATABASE` is DDL and
  implicitly commits in MySQL, which silently breaks transaction rollback.
- Helpers in `tests/Pest.php`: `registerUser()`, `orgHeaders()`, `apiAs()`,
  `giveRole()`, `inviteToOrg()`, `usingRedisCache()`, `dropTestTenantDatabases()`.
- Use `apiAs()` (or call `app('auth')->forgetGuards()`) whenever a test acts as
  more than one user — the guard caches the first user it resolves, so a later
  request silently keeps acting as the earlier one.
- Assertions against tenant data must run inside `$tenant->run(...)`.

**Never edit PHP, move migrations, or run `composer require` while the suite is
running.** It invalidates the run and produces failures that look real but are
not. This has cost three runs already. Wait for it to finish.

## Quality gates (all must pass)

```bash
./vendor/bin/pint --dirty            # style
./vendor/bin/phpstan analyse         # level 6, must be [OK] No errors
./vendor/bin/pest                     # 171 tests, ~47 min
php artisan l5-swagger:generate       # regenerate the API spec
```

## OpenAPI

Annotate every endpoint with `#[OA\...]` attributes on the controller method.

- A class-level `#[OA\Schema]` means *this class is that schema* — **one schema
  per class**. Shared schemas live in `app/OpenApi/` as one holder class each.
- After generating, verify **both directions** against `route:list`: no
  undocumented `/api/v1` route, and no documented path that isn't a real route.
  A documented path that 404s is worse than no docs. (A wrong invoice-download
  path shipped exactly this way and was caught only by that check.)
- Descriptions should record *why* a contract is what it is — why a quota breach
  is 402, why cancelling leaves a grace period — not restate the method name.

## Honest state

- Coverage has **never been measured** (no driver locally). Do not claim a
  number; CI reports one.
- Stripe/Cashier paths are written to the contract but **unverified against live
  Stripe** — no keys.
- Social login is **not built**, despite being in the spec.

## Topic skills

Detailed standards live in sibling folders under `.claude/skills/backend/`, each
its own skill. Reach for them when the task centers on that topic — but the
tenancy rules above always override anything they say.

| Skill | Folder | Use when |
|---|---|---|
| API design | `backend/api/` | Building/reviewing endpoints, resources, versioning, error shapes, rate limiting |
| Architecture | `backend/architecture/` | Deciding which layer owns logic (Controller → Request → Service → Repository → Handler → Job/Event → Policy) |
| Code review | `backend/code-review/` | Reviewing a diff/PR or your own change before committing |
| Commit | `backend/commit/` | Staging and writing Conventional Commit messages |
| Database | `backend/database/` | Migrations, modeling, relationships, indexing, query correctness |
| Debugging | `backend/debugging/` | Something is broken/throwing; reproduce → isolate → diagnose |
| DevOps | `backend/devops/` | Deploy, CI/CD, env/config, workers, scheduler, rollbacks |
| Docker | `backend/docker/` | Dockerfiles, compose services, container build/runtime issues |
| Documentation | `backend/documentation/` | READMEs, docs/ guides, ADRs, runbooks, API docs |
| Git | `backend/git/` | Branching, PRs, conflicts, merge/rebase |
| Laravel | `backend/laravel/` | General framework conventions and layered feature work |
| Linux server | `backend/linux-server/` | Nginx/PHP-FPM, TLS, Supervisor, cron, OPcache, hardening |
| Performance | `backend/performance/` | Slow endpoint, N+1, caching, eager loading, profiling |
| Queues | `backend/queues/` | Idempotent jobs, retries/backoff, failed jobs, workers, scheduler |
| Security | `backend/security/` | Authz/authn, validation, mass-assignment, CSRF/XSS/SQLi, secrets |
| Spatie | `backend/spatie/` | Roles/permissions, gates, seeding permissions, audit logging |
| Stripe | `backend/stripe/` | Payment intents, webhooks, idempotency, refunds |
| Telescope | `backend/telescope/` | Local/staging profiling and production-safe config |
| Testing | `backend/testing/` | Writing tests, factories, HTTP assertions, mocking integrations |
| Project analysis | `backend/project-analysis/` | Onboarding, navigating the codebase, planning a cross-module change |
| Frontend (API surface) | `backend/frontend/` | The backend's browser-facing pages + the JSON contract the React SPA depends on — **not** Blade UI |

> **Note.** `backend/frontend/` covers only the *backend side* of the UI
> boundary (the API is headless). Actual React UI work uses the **top-level
> `frontend` skill** and the `frontend/` app.
