---
name: project-analysis
description: How to quickly understand and navigate THIS codebase — a Laravel 13 multi-tenant SaaS platform (database-per-tenant) with a separate React SPA. Covers domains, layered architecture, the tenancy model, and where things live. Use when onboarding, exploring an unfamiliar area, or planning a change that spans modules.
---

# Project Analysis (multi-tenant SaaS platform)

## Purpose

Orient quickly in this specific codebase so changes land in the right place with
the right patterns. This skill is the map: what the system is, how it's
organized, and where to look first.

## Scope

A **Laravel 13 API backend** (`backend/`) plus a separate **React 19 SPA**
(`frontend/`). The backend is a headless JSON API using **database-per-tenant**
multi-tenancy (`stancl/tenancy` v3), Sanctum, Spatie Permission, and Cashier.
Reference companion: `docs/GUIDE.md` (whole-project guide with demo
credentials). The React UI is covered by the top-level `frontend` skill.

## What the system is

A multi-tenant SaaS where every user belongs to one or more **Organizations
(tenants)**. Each org gets its own MySQL database. Domains: **auth &
organizations**, **customers (CRM)**, **projects**, **tasks**, **calendar/events
(with recurrence)**, **files + public shares**, **team & invitations**,
**billing (Cashier/Stripe)**, **notifications**, **audit log**, and a
**Super Admin platform-admin area** (organization management, impersonation,
usage limits, stats).

## The critical concept — which database?

This is the equivalent of another app's "core status model": **every query runs
against either the central DB or a tenant DB, and picking the wrong one is the
defining bug class.**

- **Central DB** (`saas_central`): `tenants`, `users`, `plans`,
  `subscriptions`, `invitations`, `login_histories`, `admin_activities`,
  `organization_stats`.
- **Tenant DB** (`tenant_<uuid>`): `customers`, `projects`, `tasks`, `events`,
  `files`, `file_shares`, `notifications`, `roles`, `permissions`,
  `activity_log`.

Models pin their connection with `App\Models\Concerns\UsesCentralConnection` or
`UsesTenantConnection`. See the `backend` (root) skill for the five tenancy traps
and the request lifecycle — read it before touching anything data-related.

## Key structure (`backend/app/`)

```
Http/Controllers/Api/V1/   Feature controllers (thin); Api/V1/Admin/ = super-admin
Http/Requests/{Domain}/    FormRequest validation (also inline validate() for ad-hoc)
Http/Resources/            JsonResource output classes; Admin/ subfolder
Http/Middleware/           InitializeTenancyForUser, EnsureUserIsActive,
                           EnforcePlanLimit, EnsureSuperAdmin
Services/                  Business logic (writes, transactions, events)
Services/Admin/            AdminAudit, ImpersonationService, OrganizationAdminService, …
Repositories/              MINIMAL — only CustomerRepository (read/query building)
Models/ (+ Concerns/)      Eloquent; connection traits in Concerns/
Policies/                  Record-level authorization
Enums/                     Role.php, Permission.php (roles/permissions defined here)
Notifications/             GenericNotification, OrganizationInvitation
Jobs/ Listeners/           DeliverWebhook, RefreshTenantStats; Stripe/permission listeners
Console/Commands/          MakeSuperAdmin, PurgeTenants, RefreshOrganizationStats, SeedDemoData
OpenApi/                   Shared #[OA\Schema] holder classes
Providers/                 App, Tenancy, Horizon, Telescope
```
Central migrations: `database/migrations/`. Tenant migrations:
`database/migrations/tenant/` (must be **anonymous classes**).

## Domain modules

Most routes sit behind `auth:sanctum` + `tenant` (+ `active`, and `limit` for
quota-bound writes). Super-admin routes use `super-admin` and skip tenant
membership (a super admin belongs to no org). The one public group —
`public/shares/{organization}/{token}` — resolves tenancy from the URL slug, no
token/header.

## Best Practices

- Start every task in the root `backend` skill (tenancy rules), then read the
  sibling controller/service/resource for the target domain.
- Map the change across layers before coding (see `architecture`).
- Reads generally go through the Service (or `CustomerRepository` for customers);
  writes go through a Service wrapped in `DB::transaction`, emitting domain
  events via `EventDispatcher`.
- Return an `App\Http\Resources\*` Resource, wrapped in the `{message, data}`
  envelope the SPA expects.

## Performance Guidelines

- Eager-load relationships; `preventLazyLoading` is on, so an N+1 throws in dev.
- Denormalized `organization_stats` (central, refreshed hourly by queued
  per-tenant `RefreshTenantStats` jobs) avoids cross-DB fan-out at scale.
- Cache must be taggable (Redis) — tags scope entries per tenant.

## Security Considerations

- The #1 risk is a query on the **wrong connection** silently reading another
  org's rows — always confirm central vs tenant.
- Public share routes are unauthenticated by design; they resolve tenancy from
  the slug and gate on a hashed token (see `security`).
- Stripe webhooks (`stripe/*`) are excluded from request-forgery protection.

## Common Mistakes

- Adding a model without pinning its connection.
- Assuming Blade/DataTables/Handlers exist — they don't; this is an API with
  Services + Resources.
- Editing an already-run migration instead of adding a new one.
- Treating the React SPA as part of the backend — it's a separate app.

## Recommended Workflow

1. Read the root `backend` skill and `docs/GUIDE.md`.
2. Find the domain's controller/service/resource/policy; read siblings.
3. Confirm which database each query touches.
4. Implement following the layered flow; emit events for side effects.
5. Run the quality gates (`pint`, `phpstan`, `pest`, `l5-swagger:generate`).

## Output Expectations

A change that lands in the correct domain and layer, uses the right database
connection, returns a Resource in the standard envelope, reuses existing Service
patterns, and cites concrete files as `path:line`.
