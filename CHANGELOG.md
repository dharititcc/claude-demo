# Changelog

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added — Super Admin: Organization Management
- **Central-context admin API** for the Super Admin to manage every organization (`/api/v1/admin/*`), behind a `super-admin` middleware that returns **404**, not 403, to everyone else — the admin surface is not advertised. These routes are deliberately outside the `tenant` middleware: they read across all organizations rather than booting into one.
- **Organizations list** with search (name, phone, owner name/email), filters (status, plan, registration date, trashed), allowlisted sorting, and pagination — plus a **platform dashboard** (counts by lifecycle state, distinct users, and cross-tenant totals). Actions: view, edit profile (never the slug — it is the tenant identifier), suspend/activate, soft-delete, restore.
- **Scalable stats rollup** (Phase 2). Per-org counts — projects, tasks, storage, last activity — live in tenant databases, so reading them live is one cross-database fan-out per org: fine for three, fatal for three thousand. A scheduled `app:refresh-org-stats` (hourly, one queued job per tenant) denormalises them into a central `organization_stats` table, so every admin read is a single central query. Un-rolled-up orgs report `null`, never a fabricated `0`.
- **Central admin audit trail** (`admin_activities`). The per-tenant activity log cannot record a platform admin suspending or purging an org — that happens in central context. This append-only log does: who did what to which org, snapshotting the org's name so the entry stays readable after a purge removes the org itself.

### Added — Super Admin: subscription detail & per-org overrides
- **Per-organization limit overrides.** Sales and support can raise, lower, set unlimited, or clear a customer's `users`/`customers`/`storage_mb` ceilings without inventing a bespoke plan. Stored as a three-way map (a key present = override with an integer or null-for-unlimited; a key absent = fall back to the plan — a distinction three nullable columns could not express). `UsageService` resolves the effective limit in one place, so **every** quota check — the `limit:` middleware, file uploads, record creation — honours an override immediately: a raised ceiling turns a live 402 into a 201 with no deploy. Every change is written to the central audit trail.
- **Billing-cycle detail.** The admin org view now shows monthly/annual, derived by matching the subscription's Stripe price against the plan's price ids — Stripe stays the source of truth for what is actually charged.
- **React override editor** with a three-way control per limit (use plan / set to N / unlimited), verified in the browser end-to-end (an override took Acme from 49/25 to 49/100 with an "override" badge).
- **Not built, on purpose:** MRR, churn, and dunning. They require live Stripe data this environment has never had, and the project's standing rule is that a fabricated metric is worse than an absent one. The scaffolding (subscription status, interval, plan) is in place for whoever wires real Stripe keys.

### Added — Super Admin: React admin UI
- **A dedicated `/admin` area in the SPA**, guarded by an `is_super_admin` route gate and shipped as its own lazy-loaded chunk. Its own layout (red accent, no org switcher — it is platform-wide, not org-scoped), a dashboard of platform stat cards, an organizations table with debounced search / status + trashed filters / sort / pagination, an organization detail view (profile, subscription, usage, and lifecycle actions: suspend, activate, delete, restore), and the read-only audit log. Metrics that the stats rollup has not yet computed render as an em dash, never a fake zero — the same honesty the API keeps.
- **Impersonation in the browser.** Starting an impersonation parks the admin's own token, swaps to the impersonation token, and drops into the normal app; a persistent amber "you are impersonating" banner (state read from `/auth/me`, so it is server-authoritative) offers a one-click stop that revokes the token and restores the admin session. Verified end-to-end in a real browser against the running backend — every page renders live data.

### Added — Super Admin: Impersonation
- **Time-boxed, audited, reversible impersonation.** A super admin can "log in as" a member of an organization to reproduce a problem. The session is a Sanctum token minted on the *target* user — so every permission check downstream evaluates as that user with no special-casing — but tagged with the real actor and confined: it acts within **one organization only** (the tenant middleware refuses any other, even orgs the target belongs to), **cannot reach the admin API** (its user is not a super admin), expires within **60 minutes** on its own, and is revoked immediately on stop. A **super admin can never be impersonated**, nor can you impersonate yourself or a non-member. Start and stop are both written to the central audit trail, and `GET /auth/me` surfaces an `impersonation` block so the SPA can show a persistent "you are impersonating" banner. Verified end-to-end against the running app before the tests were written.

### Fixed — Super Admin lifecycle safety
- **A "soft delete" was dropping the tenant's physical database — irreversibly.** stancl maps Eloquent's `deleted` event to `TenantDeleted`, which was wired to `DeleteDatabase`; because `deleted` fires on a *soft* delete too, `$tenant->delete()` destroyed the org's entire database. Database destruction is now decoupled from the model lifecycle entirely: soft-delete keeps the database (reversible via restore), and the DB is dropped **only** by the new `tenants:purge` command — after a retention window, refusing to run unattended in production, on rows deleted long ago.
- **`Tenant::restore()` never worked.** stancl's VirtualColumn trait funnels any attribute absent from `getCustomColumns()` into the `data` JSON blob on save; `deleted_at` was not listed, so restore set it to null in memory but the real column was never cleared — a "restored" org stayed invisible forever. A soft *delete* survived only because it is a direct query update that bypasses the model. `deleted_at` is now a declared custom column. (Latent since Phase 1; surfaced the first time restore was exercised, by the test that restores an org.)

### Changed — Phase 6: Laravel 13
- **Upgraded Laravel 12 → 13** (`v12.64.0` → `v13.20.0`). The original spec called for Laravel 12 and this supersedes it. Verified against a green baseline first (171 passing on 12) so any post-upgrade failure would be unambiguous. `laravel/tinker` → `^3.0` was the only other dependency needing a bump; **stancl/tenancy v3.10.0 already declared `^13.0`**, which was the one package that could have blocked this outright, and Cashier/Horizon/Telescope/Sanctum/activitylog/L5-Swagger all supported 13 at their installed versions.
- **`php` constraint `^8.2` → `^8.3`.** Laravel 13 requires `^8.3`, so the old constraint advertised support for a PHP version the app could no longer run on.
- **CSRF middleware renamed** — `validateCsrfTokens()` → `preventRequestForgery()`. The old name survives as a deprecated alias. Laravel 13's version adds `Sec-Fetch-Site` origin verification; the existing `stripe/*` exclusion covers it, since a server-to-server webhook from Stripe sends no such header.
- **Cache `serializable_classes` added** (new in 13; blocks PHP deserialization gadget chains if `APP_KEY` leaks — which now also decrypts every 2FA secret). Set to an explicit empty allow-list: an audit of all three cache writes found `dashboard:stats` stores a nested array of scalars and the two `AuthService` writes store integers. Nothing here caches an object. A missing entry fails closed at read time, so the list is documented in `config/cache.php` rather than left to guesswork.

### Added — Phase 6: Hardening
- **Two-factor authentication (TOTP, RFC 6238).** Enrolment is two steps — a secret is issued, and 2FA only starts guarding sign-in once a code from the authenticator confirms it, so a silently failed QR scan cannot lock a user out of their own account. Login stops at `202` with a short-lived challenge handle, which is exchanged with a code (or a single-use recovery code) for an API token. A wrong code costs one of five attempts rather than the whole sign-in; the fifth destroys the challenge. Disabling 2FA or rolling recovery codes re-checks the password, because a stolen bearer token must not be able to strip the factor that exists to survive a stolen password. Secrets and recovery codes are encrypted at rest.
- **Deployment guide and production checklist** ([docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)) — deploy order, the non-optional requirements and what each one breaks if ignored, backup strategy for database-per-tenant, and an honest list of known gaps. The README had linked to this file since Phase 1 without it existing.
- **Horizon and Telescope dashboards gated on `is_super_admin`** instead of Laravel's default (empty) email allow-list. Both expose data across every organization.

### Fixed — Phase 6
- **Two-factor authentication was unreachable, and the README sold it anyway.** `User::hasTwoFactorEnabled()` existed and `LoginController` branched on it, but no endpoint could ever enable 2FA — so the flag was always false, the challenge branch was dead code, and `consumeTwoFactorChallenge()` had no caller. The README advertised "TOTP 2FA, Google & GitHub social login" regardless. 2FA is now real; the social-login claim has been removed until it is.
- **TOTP replay protection was silently inert.** Codes stay valid for a whole 30-second step, so RFC 6238 §5.2 requires accepting each at most once. The guard recorded the accepted step — but google2fa returns a bare `true` instead of the step when handed a null "last used" value, which MySQL stored as `1`; every later check then compared against step 1 instead of the real one (~58,000,000) and passed. A code was reusable for its full window. Found by the test that replays a code, not by reading the code.
- **CI ran the entire suite twice.** A bare run, then a second identical run under `--coverage` — a 210-minute budget in which the second re-proved exactly what the first had, and could not fail the build anyway because it was `continue-on-error`. Coverage instrumentation does not change test outcomes. Now a single run, on pcov instead of xdebug (xdebug's coverage carries the step-debugger and roughly triples an already-slow suite).
- **The test suite spent two-thirds of its time on fsync, not SQL.** Provisioning one tenant issues ~118 DDL statements and MySQL flushed the log and binlog on each. Tests now opt out of binary logging per session — scoped to the connection, so it cannot affect a developer's real data — taking the local suite from ~69 to 47 minutes (171 passing, 800 assertions, 2833s) while carrying 17 more tests than the ~69-minute figure. CI additionally disables `innodb_flush_log_at_trx_commit`/`sync_binlog` globally, which is only acceptable because that database is a throwaway container.
- **Any authorization check outside tenant context crashed the request.** Spatie registers a global `Gate::before` that resolves every ability against the `permissions` table, which exists only in tenant databases — so `/horizon`, `/telescope`, and any future policy on a central route died with `Table 'saas_central.permissions' doesn't exist` rather than denying cleanly. Spatie's hook is now disabled in favour of a tenancy-aware replacement that only consults permissions when a tenant is active.
- **CI claimed to enforce 80% coverage that has never been measured.** No coverage driver exists in the development environment and the Stripe paths are untestable without live keys, so the gate would have failed the first push for unquantified reasons. Coverage is now reported, not enforced, until the real figure is known — and the slow suite has an explicit timeout so a timeout cannot masquerade as a test failure.

### Added — Phase 5: Calendar, Audit, Notifications, Files
- **Calendar**: events, meetings, and reminders. Recurring series are stored as a rule (frequency/interval/by-day/until/count) and expanded on demand for a bounded window rather than materialised as rows; a single occurrence can be cancelled or moved without touching the rest of the series.
- **Audit logs**: every create/update/delete on customers, projects, tasks, and events is recorded to the organization's own audit trail (in its tenant database), capturing only the fields that actually changed. Read-only by API.
- **Notifications**: an in-app inbox scoped per organization (a user in three orgs has three inboxes), plus outbound **webhooks** — signed with HMAC-SHA256, delivered on a queue, retried with backoff, and auto-paused after repeated failures.
- **File manager**: a folder tree with a materialised path, uploads guarded by the plan's storage quota and an executable deny-list, and public **share links** with hashed tokens, optional expiry, password, and download cap.

### Added — Phase 5: Projects & Tasks
- **Projects**: CRUD with a customer link, task-progress rollup, overdue filter, and members; status and completed_at are kept in step so they cannot disagree.
- **Tasks**: a Kanban board (every column always rendered), drag-to-reorder using float positions so a move writes one row rather than renumbering the column, subtasks that cascade on soft-delete, priorities, and labels.
- **Time tracking**: start/stop timers (at most one running per user), log-after-the-fact, a denormalised per-task total, and a guard so people can only edit their own timesheet unless they can delete tasks.
- **React**: Projects grid with progress bars, a Kanban board with optimistic drag-and-drop and rollback, and a task editor.

### Added — Phase 4: Stripe billing
- **Cashier 16** with the *organization* as the billable entity, not the user — someone in several organizations must not carry several payment methods, and a subscription must survive its purchaser leaving.
- **Plans**: Free/Starter/Pro/Enterprise with monthly and annual prices, per-plan trials, and usage limits. Stripe price ids are the source of truth for money; local amounts are display-only.
- **Subscriptions**: subscribe, swap (proration invoiced immediately rather than surprising the customer later), cancel to a grace period, resume. Trials carry across plan changes, and cancelling then re-subscribing does not grant a second free trial.
- **Invoices** with PDF download, read live from Stripe rather than kept as our own copy of records Stripe owns.
- **Tax** delegated to Stripe — rates are jurisdictional and change without notice.
- **Usage limits** enforced at create-time (`limit:` middleware), returning **402 Payment Required** so clients prompt an upgrade instead of showing "access denied".
- **Webhooks** reconciling plan and period end, covering what happens outside the app: renewals, dunning, and plan edits made in Stripe's dashboard.
- **Billing UI**: plans grid, usage bars, invoices, cancel/resume with grace-period banner.

> ⚠️ Subscribe/swap/invoice paths are written to Cashier's contract but are **unverified against live Stripe** — this environment has no API keys. Everything Stripe is not responsible for (plan resolution, limits, permissions, fallbacks) is tested.

### Added — Phase 3: Organizations, teams, files
- **Organization settings**: name, logo upload, timezone, currency, language. The slug is immutable — it is the tenant identifier clients send in `X-Organization`.
- **Teams**: member list showing each person's role *in this organization*, role changes, and removal. An organization can never lose its last owner, and nobody can remove themselves.
- **Invitations**: emailed links with hashed, single-use tokens and a 7-day expiry; acceptance is bound to the invited address, so holding the link is not enough. Re-inviting replaces the previous invitation rather than stacking duplicates.
- **Attachments**: uploads on customers, with an executable/script deny-list (`.php`, `.html`, `.svg`, …) that does not trust the client-reported MIME type, generated storage paths, and file deletion tied to row deletion.
- **React**: Team, Settings, Customer detail, and Accept-invitation pages; navigation filtered by permission.

### Added — Phase 3: Customers vertical slice
- **Customers module**: CRUD, search (name/email/company/phone), status & tag filtering, allow-listed sorting, pagination, soft delete + restore.
- **CSV import/export**: export streams and chunks (constant memory); import validates per-row and reports skipped rows rather than aborting the file.
- **Tenant schema**: `customers`, `tags`/`taggables`, `notes`, `attachments` — the last three polymorphic, so Projects and Tasks reuse them.
- **Authorization**: `CustomerPolicy` enforcing the per-organization permission matrix.
- **Layering**: `CustomerRepository` (query construction) and `CustomerService` (business operations) keep controllers thin.
- **Dashboard API**: totals, status breakdown, active-customer lifetime value, zero-filled 6-month growth series, recent customers; cached 5 min per organization.
- **OpenAPI/Swagger** (L5-Swagger): all 90 API operations documented, checked against the router in both
  directions so the spec cannot silently drift from the routes. Descriptions record the reasoning behind
  a contract — why a quota breach is 402 rather than 403, why a share link carries the org slug, why
  cancelling leaves a grace period — rather than restating the method name.
- **React SPA**: lazy-loaded router, protected/guest routes, error boundary, app layout, organization switcher, dark mode, login/register, dashboard with Recharts, customers table with search/filter/sort/paginate and a create/edit dialog.
- `php artisan app:demo` — two organizations with separate databases, users at different role levels, and seeded customers.
- Tests: Pest coverage for the Customers module (CRUD, search, RBAC, isolation); Vitest for the auth store and login form.

### Added — Phase 2: Tenancy, auth, RBAC
- `stancl/tenancy` database-per-tenant; tenant database provisioned, migrated, and seeded on organization creation.
- Central/tenant connection split (`UsesCentralConnection` / `UsesTenantConnection`).
- Roles and permissions inside each tenant database — 5 organization roles, 29 permissions.
- Super Admin as a central flag with a `Gate::before` bypass.
- Sanctum auth: register, login, logout, me, sessions, login history, change/forgot/reset password, email verification.
- Security: password policy, failed-login lockout (429), suspended account and organization guards.
- `X-Organization` tenant resolution middleware with membership enforcement.

### Added — Phase 1: Foundation
- Monorepo structure, governance files, Docker Compose stack, GitHub Actions CI.
- Laravel 12 API and React 19 + TypeScript SPA bootstraps. (Upgraded to Laravel 13 in Phase 6.)

### Fixed
- **The app root 500'd on any non-`localhost` host.** The stancl scaffold's `routes/tenant.php` registered a domain-identified `/` route that collided with the application root and threw `TenantCouldNotBeIdentifiedOnDomainException` (e.g. on `demo.test`). We identify tenants by the `X-Organization` header, not by domain, so those routes were removed and the root now returns a small JSON pointer.
- **Spatie's activity_log migrations could not run per-tenant.** They use named migration classes, which cannot be declared twice in one process — so provisioning a second tenant fatally redeclared the class. Converted to anonymous classes.
- **Tenant context leaked between requests under Octane.** The tenant-init middleware initialized tenancy but never ended it, so on a reused container the next request began with the previous organization's database as the default connection. It now reverts to central in `terminate()`. (The same class of bug as the Sanctum-token and billing-validation fixes: a swapped connection outliving its scope.)
- **Timer rendering 500'd.** Carbon's `diffInSeconds()` returns a float, but `elapsedSeconds()` was typed `int`, so serialising any time entry threw a TypeError.
- **Soft-deleting a task orphaned its subtasks.** The database FK cascade only fires on hard delete, so subtasks reappeared at the root of the board with their parent hidden. A model event now cascades the soft delete, and restore reverses it.
- **Stripe price ids would have been null in production.** The seeder read them via `env()`, which returns null once `config:cache` runs — so every paid plan would have silently lost its price id in production while working perfectly in development. Now read from `config/billing.php`.
- **Cashier wrote `stripe_id` into a JSON blob.** stancl's VirtualColumn trait funnels undeclared attributes into `data`, so Cashier's `where('stripe_id', …)` would never have matched — creating a duplicate Stripe customer on every request. The billing columns are now declared in `getCustomColumns()`.
- **Cashier's schema assumed an integer `users` billable.** Its migrations were rewritten for a UUID-keyed `Tenant` (`tenant_id` as a string, not `foreignId('user_id')`).
- **Billing page depended on Stripe's uptime.** Rendering "renews on…" called `asStripeSubscription()` — a live API call, twice, per page load. The period end is now cached locally and refreshed by webhook.
- **Dashboard 500'd on any non-taggable cache store.** Tenancy tags cache entries per organization; `file` and `database` cannot tag, so the failure surfaced only when a controller called `Cache::remember()` inside tenant context, as an opaque "This cache store does not support tagging". The requirement is now asserted at boot with an actionable message, and documented.
- **Sanctum tokens resolved against the tenant database.** The token model now pins to the central connection. Relying on "auth middleware runs before tenancy" breaks under Octane, where the container — and any leftover tenant connection — is reused between requests.
- **Route-model binding resolved against the central database.** The `tenant` middleware now runs before `SubstituteBindings`, so `{customer}` binds against the correct database.
- **`authorizeResource()` fatally errored.** It registers controller middleware, which Laravel 11+ removed from the base controller; authorization is now an explicit `authorize()` call per action.
- **Login throttling returned 422 instead of 429**, so clients could not distinguish "fix your input" from "back off".
- **Forms bypassed Zod validation.** Native browser constraint validation (`type="email"`) blocked submit before react-hook-form ran, showing an unstyled native tooltip; forms now set `noValidate`.
- **API responses misreported user state** (`status: null`) — model defaults now mirror the database defaults.
- `LoginHistory` attempted to write a non-existent `created_at` column.

### Planned
- Phase 3 remainder: organization settings, teams & invitations, customer detail page, attachment uploads.
- Phase 4: Stripe billing (plans, trials, coupons, invoices, taxes, usage limits).
- Phase 5: Projects, Tasks, Calendar, Files, Notifications, Audit Logs.
- Phase 6: 2FA, social login, coverage ≥ 80%, deployment hardening.

[Unreleased]: https://example.com/compare/main...HEAD
