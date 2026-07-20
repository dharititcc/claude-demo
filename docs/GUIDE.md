# Project Guide — Multi-Tenant SaaS Platform

A practical, end-to-end guide to what this platform is, how it works, how to run
it locally, and how to sign in and take a tour with ready-made demo accounts.

> For the deploy-to-production story see [DEPLOYMENT.md](DEPLOYMENT.md); for what
> is built vs. planned see [ROADMAP.md](ROADMAP.md).

---

## Contents

- [1. What this is](#1-what-this-is)
- [2. How it works (architecture)](#2-how-it-works-architecture)
- [3. Tech stack](#3-tech-stack)
- [4. Run it locally](#4-run-it-locally)
- [5. Demo credentials](#5-demo-credentials)
- [6. Take the tour](#6-take-the-tour)
- [7. How the API works](#7-how-the-api-works)
- [8. Modules reference](#8-modules-reference)
- [9. Roles & permissions](#9-roles--permissions)
- [10. The Super Admin control plane](#10-the-super-admin-control-plane)
- [11. Testing](#11-testing)
- [12. Honest state — what's not done](#12-honest-state--whats-not-done)

---

## 1. What this is

A **multi-tenant SaaS platform**: many independent organizations ("tenants")
sign up and operate in complete data isolation. Each has its own admin, staff,
customers, projects, tasks, files, billing, and audit trail — and none can see
another's data.

It ships as two applications:

- **Backend** — a Laravel 13 REST API (`backend/`).
- **Frontend** — a React 19 single-page app (`frontend/`) that consumes the API.
  There are no server-rendered (Blade) pages beyond email templates.

On top of the tenant-facing app sits a **Super Admin control plane** for managing
every organization across the platform.

---

## 2. How it works (architecture)

### Database-per-tenant

The defining design choice: **every organization gets its own database.**

- A **central** database holds what is platform-wide: the tenant registry,
  user identities, plans, subscriptions, invitations, login history, the
  platform audit log, and a denormalized per-org stats rollup.
- Each organization gets a dedicated **`tenant_<uuid>`** database, provisioned
  automatically on signup, holding that org's customers, projects, tasks,
  events, files, roles, and activity log.

```
                     React 19 SPA (frontend/)
                              │  HTTPS + Bearer token + X-Organization
                     Laravel 13 API (backend/)
                              │
          ┌───────────────────┴───────────────────┐
    Central database                          Redis
    (tenants, users, plans,                   (cache · queue · Horizon)
     subscriptions, audit)
          │ provisions
   ┌──────┼───────────────┐
tenant_1        tenant_2   …   tenant_N        (isolated databases)
(customers, projects, tasks, files, roles, activity_log)
```

### The request lifecycle

A tenant-scoped API request resolves in this order:

1. **Authenticate** — the Sanctum bearer token identifies the user against the
   *central* database.
2. **Select the organization** — the client sends an **`X-Organization`** header
   (the org's slug). Middleware checks the user is a member and boots that
   organization's database as the active connection.
3. **Run** — the controller now reads and writes the tenant's own database.

A user can belong to several organizations and switch between them; their **role
is resolved per organization** from that tenant's database. A **Super Admin**
(a central `is_super_admin` flag) may act across every organization without
being a member.

> **Why this matters:** the platform's whole promise is isolation, and its
> defining hazard is a query hitting the wrong database connection — which fails
> silently, not loudly. Models pin their connection deliberately; see
> [`.claude/skills/backend`](../.claude/skills/backend/SKILL.md) for the details.

---

## 3. Tech stack

| Layer | Technology |
|---|---|
| Backend | Laravel 13 · PHP 8.3 · MySQL 8 · Redis |
| Auth | Laravel Sanctum (token) · TOTP two-factor |
| Tenancy | `stancl/tenancy` v3 (database-per-tenant) |
| Billing | Laravel Cashier (Stripe), organization as billable |
| Queues / ops | Horizon · Telescope |
| RBAC | Spatie Permission (roles resolved per tenant) |
| Quality | Pest · PHPStan (level 6) · Laravel Pint · L5-Swagger |
| Frontend | React 19 · TypeScript · Vite · React Router |
| Data / state | TanStack Query · Axios · Zustand |
| UI | TailwindCSS · shadcn-style components · Recharts · React Hook Form + Zod |
| Frontend tests | Vitest · Testing Library |

---

## 4. Run it locally

### Prerequisites

- PHP 8.3+, Composer
- Node 20+, npm
- MySQL 8
- Redis (required — tenancy tags cache entries per organization, which the
  file/array cache drivers cannot do)

> **Windows note:** Horizon needs the `pcntl`/`posix` PHP extensions, which don't
> exist on Windows. `composer.json` declares them as platform overrides so local
> installs resolve; run Horizon itself in the Linux/Docker container. Everything
> else runs fine on Windows (this project is developed under Laragon).

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate

# Point DB_* at your MySQL, then create the central database:
#   CREATE DATABASE saas_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
php artisan migrate

php artisan serve            # API at http://localhost:8000
php artisan horizon          # queue workers (separate terminal)
```

### Frontend

```bash
cd frontend
npm install
cp .env.example .env         # VITE_API_URL defaults to http://localhost:8000
npm run dev                  # SPA at http://localhost:5173
```

### Seed the demo data

This creates two organizations with separate databases, users at different role
levels, and sample customers — enough to see tenant isolation and RBAC working:

```bash
cd backend
php artisan app:demo         # add --fresh to drop and recreate the demo orgs
```

It prints a table of sign-in credentials when it finishes (also listed in
[section 5](#5-demo-credentials)).

### Create a Super Admin

The demo seeder does **not** create a platform admin. Make one with:

```bash
php artisan app:make-super-admin you@example.com
# prompts for confirmation and generates a password (shown once),
# or pass --password=... to set your own
```

A super admin is a platform-level identity (not a member of any org) that can
manage every organization. See [section 10](#10-the-super-admin-control-plane).

---

## 5. Demo credentials

Run `php artisan app:demo` to create these. All demo passwords are the same:

**Password (all demo users):** `Demo!Passw0rd#2026`

| Email | Name | Access |
|---|---|---|
| `owner@acme.test` | Alex Owner | **Owner** of Acme Inc · **Manager** in Globex Corp |
| `viewer@acme.test` | Vic Viewer | **Viewer** in Acme Inc (read-only) |
| `owner@globex.test` | Gale Globex | **Owner** of Globex Corp |

Seeded data: **Acme Inc** has 24 customers, **Globex Corp** has 7. Signing in as
`owner@acme.test` and switching organizations is the quickest way to watch the
data change — that's tenant isolation in action.

### Super Admin

Not seeded — created with the command above. If you followed this project's own
setup, the platform admin is:

| Email | Password | Access |
|---|---|---|
| `superadmin@itcc.co` | `Sup3r!Admin#2026` | Platform-wide — every organization |

> ⚠️ **Local demo only.** This is a development credential in plaintext. Change
> it before anything leaves your machine (`PUT /api/v1/auth/password` after
> signing in), and never seed a known super-admin password in production.

---

## 6. Take the tour

With both servers running and the demo seeded, open **http://localhost:5173**.

### As an owner — `owner@acme.test`

1. **Sign in.** You land on the **Dashboard** with aggregate metrics for the
   active organization (Acme).
2. **Switch organizations.** Use the org switcher (top of the sidebar) to move to
   Globex Corp — the customer counts and every screen change. Same login,
   different database. Notice your role differs per org (Owner in Acme, Manager
   in Globex).
3. **Customers.** Browse, search, filter, sort; open a customer for notes and
   tags; export the current selection as CSV.
4. **Projects & Tasks.** Projects roll up task progress; the Tasks board is a
   Kanban with drag-to-reorder. Try dragging a card between columns.
5. **Calendar, Files, Team, Billing, Settings.** Invite a teammate, upload a
   file (quota-enforced), create a share link, review the plan and usage.

### As a viewer — `viewer@acme.test`

Sign out and back in as the viewer. The same Acme data is visible, but
**create/edit/delete controls are hidden or refused** — the UI hides what you
can't do, and the API independently returns `403` if you try anyway. This is
role-based access control, scoped to the organization.

### As a Super Admin — `superadmin@itcc.co`

A red **Platform Admin** link appears in the sidebar. It opens a separate control
plane (see [section 10](#10-the-super-admin-control-plane)): every organization,
platform stats, lifecycle actions, per-org limit overrides, impersonation, and a
full audit log.

---

## 7. How the API works

Base URL (local): **`http://localhost:8000/api`**. Everything is versioned under
`/v1`.

### Three tiers of access

1. **Public** — no token: register, login, password reset, public file shares.
2. **Authenticated (central context)** — a valid bearer token; acts on
   platform-level data (your profile, your organizations, the admin API).
3. **Tenant-scoped** — additionally requires an **`X-Organization`** header; the
   request runs against that organization's database.

### Two headers do the work

```http
Authorization: Bearer <token>      # who you are (from login)
X-Organization: acme-inc           # which organization to act in (slug)
```

### A minimal example

```bash
# 1. Log in (public) — returns a token
curl -s http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"email":"owner@acme.test","password":"Demo!Passw0rd#2026"}'

# 2. List Acme's customers (tenant-scoped) — needs both headers
curl -s http://localhost:8000/api/v1/customers \
  -H 'Authorization: Bearer <token>' \
  -H 'X-Organization: acme-inc' -H 'Accept: application/json'
```

If the account has two-factor enabled, login returns **`202`** with a challenge
handle instead of a token; exchange it plus a code at
`POST /v1/auth/2fa/challenge`.

### Interactive docs

Full OpenAPI/Swagger docs (all 100+ operations) are served at:

```
http://localhost:8000/api/documentation
```

Regenerate after changing annotations with `php artisan l5-swagger:generate`.
A Postman collection lives in [`postman/`](../postman/).

---

## 8. Modules reference

| Module | What it does |
|---|---|
| **Authentication** | Login, registration, email verification, password reset & change, session management, **TOTP two-factor** (enrol/confirm, recovery codes, single-use codes), login history. |
| **Organizations** | Name, logo, slug (immutable — it's the tenant identifier), timezone, currency, language, status. A user can own/join several. |
| **Teams & RBAC** | Members, invitations, and six roles resolved **per organization** (see section 9). |
| **Customers** | CRUD, search across name/email/company, status filter, allow-listed sorting, notes, tags, CSV export. |
| **Projects** | CRUD with a customer link, task-progress rollup, overdue filter, members. |
| **Tasks** | Kanban board (float-position ordering — a drag writes one row), subtasks, priorities, labels, time tracking. |
| **Calendar** | Events, meetings, reminders; recurring series stored as a rule and expanded on demand. |
| **Files** | Folder tree, uploads guarded by the plan's storage quota and an executable deny-list, and public share links (hashed token, optional expiry/password/download cap). |
| **Notifications** | In-app inbox scoped per organization, plus outbound **webhooks** (HMAC-signed, queued, retried, auto-paused on repeated failure). |
| **Billing** | Stripe (Cashier) with the **organization** as the billable entity; plans, trials, coupons, invoices, taxes, and usage limits enforced at create-time (returns `402`, not `403`). |
| **Audit logs** | Every create/update/delete on core records, in the organization's own database, read-only by API. |

---

## 9. Roles & permissions

Six roles. Five are **organization-scoped** (resolved from the tenant database);
one is **platform-level**.

| Role | Scope | Typical capability |
|---|---|---|
| **Super Admin** | Platform | Everything, across every organization (see section 10). |
| **Tenant Owner** | Organization | Full control of their org, including billing and deletion. |
| **Admin** | Organization | Manage members, settings, and all modules. |
| **Manager** | Organization | Manage records; some administrative limits. |
| **Employee** | Organization | Create and edit records they work on. |
| **Viewer** | Organization | Read-only. |

The frontend hides controls a role can't use (a convenience), but **the API
re-authorizes every request** — the UI is never the security boundary. A quota
breach returns **`402 Payment Required`** (upgrade prompt), distinct from a
permission denial's **`403`**.

---

## 10. The Super Admin control plane

Signed in as a super admin, the **Platform Admin** area (`/admin`) manages every
organization from central context — no `X-Organization` header, reading across
all tenants. Non-admins get a `404`; the surface isn't advertised.

- **Dashboard** — organizations by lifecycle state (active/trial/suspended/
  expired/paid), total users, and cross-tenant totals summed from a denormalized
  stats rollup (refreshed hourly, so the list scales to thousands of orgs
  without a per-org query fan-out).
- **Organizations** — searchable/filterable/sortable table; view, edit profile,
  **suspend/activate**, **soft-delete/restore**.
- **Lifecycle safety** — a soft delete cuts access but **keeps the database**
  (reversible via restore). The physical database is only ever dropped by the
  deliberate, retention-gated `php artisan tenants:purge` command.
- **Per-org limit overrides** — raise, lower, set unlimited, or clear a single
  organization's `users`/`customers`/`storage_mb` ceilings without a bespoke
  plan. Honoured everywhere quota is enforced, immediately.
- **Impersonation** — "log in as" an org member to reproduce a problem. The
  session acts as that user but is **confined to one organization**, **can't
  reach the admin surface**, **expires within an hour**, is **revocable**, and
  can **never** target another super admin. A banner shows you're impersonating;
  start and stop are both audited.
- **Audit log** — every platform-admin action (suspend, edit, delete, restore,
  purge, impersonate), read-only, surviving even a purged organization.

---

## 11. Testing

```bash
# Backend — needs a real MySQL 8 and, for cache-isolation tests, Redis
cd backend && ./vendor/bin/pest

# Frontend
cd frontend && npm run test
```

The backend suite runs against **real MySQL**, provisioning a real tenant
database per test — SQLite cannot prove database-per-tenant isolation, which is
the platform's central claim, so the suite pays that cost on purpose (it is
slow). CI additionally enforces Pint (style) and PHPStan level 6 (static
analysis) on every push.

---

## 12. Honest state — what's not done

Stated plainly, because a fabricated capability is worse than an absent one:

- **Stripe billing paths are unverified against live Stripe.** Subscribe, swap,
  and invoice flows are written to Cashier's contract but this environment has
  no API keys. Everything Stripe isn't responsible for is tested.
- **MRR / churn / dunning are not built** — they need live Stripe data.
- **Test coverage has never been measured** — no coverage driver exists in the
  local PHP build; CI is the first place a real percentage appears. Don't trust
  a coverage claim until then.
- **Social login (Google/GitHub) is not built** — it's in the original spec.
  Password auth, email verification, two-factor, and sessions are.
- **Metrics awaiting the first stats rollup** render as `—`, never a fake `0`.
