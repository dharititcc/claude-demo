# Delivery Roadmap

This project is built **incrementally**. Every phase must be runnable, migrated, and green in CI before the next begins. This file is the source of truth for what is done vs. planned.

Legend: ✅ done · 🚧 in progress · ⬜ planned

---

## Phase 1 — Foundation ✅
- ✅ Monorepo structure & git
- ✅ Governance files (README, LICENSE, CONTRIBUTING, SECURITY, CoC, CHANGELOG, editorconfig, gitignore, gitattributes)
- ✅ Laravel backend bootstrap — started on 12.63 (pinned to `^12`), **upgraded to 13.20 in Phase 6**
- ✅ React 19 + TS + Vite 8 frontend bootstrap (typecheck, tests, build verified)
- ✅ Docker Compose (backend, horizon, nginx, frontend, mysql, redis, phpmyadmin)
- ✅ CI workflows (Pint, PHPStan, Pest, Vitest, build, migration check)

## Phase 2 — Tenancy + Auth + RBAC 🚧
- ✅ `stancl/tenancy` database-per-tenant; tenant DB auto-provisioned, migrated, and seeded on organization creation
- ✅ Central vs tenant connection split (`UsesCentralConnection` / `UsesTenantConnection`)
- ✅ Roles & permissions live **inside each tenant database** (5 org roles, 29 permissions)
- ✅ Super Admin as a central flag with `Gate::before` bypass (spans all tenants)
- ✅ Sanctum token auth: register, login, logout, me, sessions, change password
- ✅ Password reset + email verification endpoints
- ✅ Security: password policy, login history, failed-login lockout (429), suspended account/org guards
- ✅ `X-Organization` tenant resolution middleware with membership enforcement
- ✅ Pest suite covering registration, login, and tenant isolation
- ✅ Two-factor authentication (TOTP + recovery codes) — delivered in Phase 6, see below
- ⬜ Social login (Google, GitHub) — **not built**; the README claimed it, which has been corrected
- ⬜ Team invitations
- ⬜ Audit log wiring (spatie/activitylog is installed, not yet applied)

## Phase 3 — Vertical Slice (Organizations → Dashboard → Customers) ✅
- ✅ Customers module: CRUD, search, filter, sort, pagination, CSV import/export, tags, notes, soft delete + restore
- ✅ Tenant schema: customers, tags/taggables, notes, attachments (all polymorphic, reusable by Projects/Tasks)
- ✅ CustomerPolicy enforcing the per-organization permission matrix
- ✅ Repository + Service layering (query building vs. business operations)
- ✅ Dashboard API (totals, status breakdown, 6-month growth series, recent customers)
- ✅ React: router with lazy-loaded pages, protected routes, error boundary, dark mode, org switcher
- ✅ Login/Register pages, Dashboard with Recharts, Customers table with search/filter/sort/paginate
- ✅ `php artisan app:demo` — two orgs with separate databases and seeded data
- ✅ Pest coverage for the slice; Vitest for the auth store and login form
- ✅ Organization settings (name, logo upload, timezone, currency, language); slug immutable
- ✅ Teams: member list with per-org roles, role changes, removal, last-owner guard
- ✅ Invitations: hashed tokens, 7-day expiry, email-bound acceptance, revoke, re-invite replaces
- ✅ Customer detail page with notes and attachments
- ✅ Attachment uploads with an executable/script deny-list and generated storage paths

## Phase 4 — Billing 🚧
- ✅ Cashier 16 with the **organization** as the billable entity (not the user)
- ✅ `plans` table + seeder (Free/Starter/Pro/Enterprise), monthly & annual prices
- ✅ Subscribe, swap (with immediate proration), cancel to grace period, resume
- ✅ Trials (preserved across plan changes; no second trial by re-subscribing), coupons
- ✅ Invoices + PDF download, streamed live from Stripe
- ✅ Tax delegated to Stripe (`Cashier::calculateTaxes()`)
- ✅ Usage limits enforced at create-time via `limit:` middleware → **402 Payment Required**
- ✅ Webhook listener syncing plan + period end (covers dashboard edits, renewals, dunning)
- ✅ Billing UI: plans, usage bars, invoices, cancel/resume
- ⚠️ **Unverified against live Stripe** — no API keys in this environment. Subscribe/swap/invoice
  paths are written to Cashier's contract but have not been exercised against Stripe's API.
- ⬜ Stripe.js card collection in the SPA (SetupIntent endpoint exists; the Elements form does not)
- ⬜ Dunning emails / failed-payment recovery UX

## Phase 5 — Remaining Modules ✅
- ✅ **Projects**: CRUD, status→completed_at derivation, customer link, progress, overdue filter, members, comments, attachments
- ✅ **Tasks**: Kanban board, drag-to-reorder (float positions), subtasks (cascade soft-delete), priority, labels
- ✅ **Time tracking**: start/stop timer (one running per user), log-after-the-fact, denormalised total, per-user timesheet guard
- ✅ **Calendar**: events, meetings, reminders; recurring series stored as a rule and expanded on demand; single-occurrence exceptions (cancel/move one instance)
- ✅ **Audit Logs**: spatie/activitylog in each tenant DB; create/update/delete recorded with only-changed-fields; read-only API
- ✅ **Notifications**: in-app (per-organization inbox in the tenant DB) + signed, queued **webhooks** with a circuit breaker
- ✅ **File Manager**: folder tree (materialised path), uploads with quota enforcement + deny-list, public share links (hashed token, expiry, password, download cap)
- ✅ React: Projects grid, Kanban board (optimistic drag), Calendar month grid
- ✅ Pest coverage for every module (projects, tasks, calendar, audit, notifications, webhooks, files, shares)
- ⬜ Slack/push notification channels (email + database + webhooks done)
- ⬜ File preview & versioning (upload/download/share/quota done)

## Phase 6 — Hardening 🚧
- ✅ **Laravel 12 → 13** (`v13.20.0`). Upgraded against a green 171-test baseline so failures could not be ambiguous. Only `laravel/tinker` needed bumping alongside it; stancl/tenancy already supported 13. PHP floor moved to `^8.3` to match. See the CHANGELOG for the CSRF rename and the cache `serializable_classes` audit
- ✅ **Deployment guide + production checklist** ([DEPLOYMENT.md](DEPLOYMENT.md)) — was referenced by the README but had never been written
- ✅ **Central-context authorization fixed** — Spatie's gate hook crashed any `Gate` check outside a tenant (see the decision table); `/horizon` and `/telescope` would have 500'd in production
- ✅ **Horizon/Telescope dashboards gated on `is_super_admin`** rather than an empty email list
- ✅ **CI honesty** — the coverage gate no longer claims to enforce an unmeasured 80%; it reports coverage instead, and the slow suite has an explicit timeout
- ✅ **OpenAPI/Swagger: 90 of 90 operations** documented across all 24 controllers, verified both directions against `route:list` — no undocumented route, no documented path that isn't a real route. Every `$ref` resolves; the billing paths are annotated to Cashier's contract but remain **unverified against live Stripe**
- ✅ **Two-factor authentication (TOTP)** — enrol/confirm/disable, recovery codes, and a login challenge. Was half-built and unreachable: `hasTwoFactorEnabled()` and the 202 branch in `LoginController` existed, but nothing could ever *enable* 2FA, so the branch was dead and `consumeTwoFactorChallenge()` had no caller. The README advertised the feature regardless
- ✅ **Replay protection (RFC 6238 §5.2)** — a code is accepted at most once. The first implementation was silently inert: google2fa returns `true` rather than the time step when given a null `$oldTimestamp`, which stored as `1` and made every later comparison pass. Caught by the test that replays a code, not by review
- ✅ **CI ran the whole suite twice** — once bare, then again under `--coverage` (a 210-minute budget) where the second run re-proved the first and could not fail the build anyway. Now one run, on pcov rather than xdebug
- ✅ **Test-suite speed: ~69 min → 47 min** (171 passing, 800 assertions, 2833s — a real full-suite measurement). Provisioning a tenant is ~118 DDL statements and the cost is fsync, not SQL: the suite now opts out of binary logging per session, which is safe because it changes nothing outside the test connection. CI additionally turns off `innodb_flush_log_at_trx_commit`/`sync_binlog` globally, which is only defensible because that database is a container deleted minutes later. (An earlier revision of this line claimed ~39 min and "1.75x, measured" — that was extrapolated from an 8-test subset and did not survive the full run. 47 min is also carrying 17 more tests than the 69-min figure, so per-test the gain is ~1.6x)
- ⬜ Coverage ≥ 80%: **still never measured.** No coverage driver exists in this PHP build and installing one was declined for now, so CI is the first place a real number can appear. The Stripe paths remain deliberately untested. Do not trust any coverage claim until that run exists
- ✅ Performance guards already in place: `preventLazyLoading`, eager loading in list endpoints, cursor pagination available, indexes on filter/sort columns, Redis cache + Horizon

## Super Admin — Organization Management 🚧
- ✅ **Phase 1 — Admin API.** Central-context `/api/v1/admin/organizations` (list/show/edit/suspend/activate) + platform stats, behind a `super-admin` middleware that 404s non-admins. Search/filter/sort/paginate. 11 tests
- ✅ **Phase 2 — Stats rollup.** `organization_stats` central table + hourly `app:refresh-org-stats` (one queued job per tenant) so cross-tenant counts scale to thousands of orgs without per-org fan-out on read. Null = not-yet-measured, never a fake zero. 6 tests
- ✅ **Phase 3 — Lifecycle safety.** Closed the data-loss trap where a soft delete dropped the tenant database; added `tenants:purge` (retention window, the only thing that drops a DB), restore, and a central `admin_activities` audit trail. Also fixed `Tenant::restore()`, broken since Phase 1 by VirtualColumn. 8 tests
- ✅ **Phase 4 — Impersonation.** "Log in as" an org member via a Sanctum token minted on the *target* user (so every permission check evaluates as them), tagged with the real actor and confined to one org and ≤60 min. Can't touch the admin surface, can't leave the chosen org, can't impersonate a super admin; start/stop both audited; revocable before expiry. Verified live end-to-end, 11 tests
- ✅ **Phase 5 — React admin UI.** A separate `/admin` area in the SPA (own layout, red accent, no org switcher), guarded by an `is_super_admin` route gate: dashboard stat cards, organizations table (search/filter/sort/paginate), org detail with lifecycle + impersonate actions, and the audit log. Impersonation swaps tokens client-side (parking the admin token to restore on stop) with a server-authoritative "you are impersonating" banner. Verified live in the browser against the running backend — every page renders real data (e.g. Acme's rolled-up "1 project", "Storage 1 MB"). typecheck/lint/build clean, 24 Vitest tests (3 new for the token-swap)
- ✅ **Phase 6 — Subscription detail & per-org overrides.** Billing cycle derived from the Stripe price; **per-organization limit overrides** (raise/lower/unlimited/clear per limit) that `UsageService` honours everywhere — one seam, so uploads and record creation enforce them immediately (verified: an override changes a live 402). Central audit entry per change; React editor with a three-way control, verified in the browser (49/25 → 49/100 + override badge). 8 backend tests. **MRR/churn/dunning deliberately NOT built** — they need live Stripe data this environment doesn't have, and a fabricated number is worse than none

---

## Architecture decisions worth knowing

| Decision | Why |
|---|---|
| **Database-per-tenant** | Strongest isolation; each organization gets `tenant_<uuid>`, provisioned automatically. |
| **Roles inside the tenant DB** | Tenancy swaps the default connection, so `$user->roles` is org-scoped for free. Avoids Spatie's `teams` feature, which assumes integer team keys (ours are UUIDs). |
| **User pinned to central DB** | One identity across organizations. Requires `UsesCentralConnection`, or queries would follow the tenant connection swap. |
| **Spatie models pinned to tenant DB** | Laravel copies the *parent's* connection onto related models that lack one — `Role` would otherwise inherit User's central connection. |
| **Super Admin ≠ Spatie role** | It spans all tenants; a per-tenant role table cannot express that. |
| **Auth before tenancy in middleware** | Sanctum resolves the token against central; only then is the connection swapped. |
| **Sanctum's token model pinned to central** | Sanctum resolves the bearer token on the *default* connection. Relying on "auth runs before tenancy" breaks under Octane, where the container (and a leftover tenant connection) is reused between requests. |
| **`tenant` middleware before `SubstituteBindings`** | Route-model binding must resolve `{customer}` against the tenant database. Without the priority override it runs first and looks in central. |
| **Tenant tables have no `organization_id`** | Isolation is by database. A stray missing `where` clause cannot leak data across organizations. |
| **Spatie's permission gate hook is replaced** | Spatie registers an unconditional `Gate::before` resolving *every* ability against the `permissions` table — which exists only per-tenant. In central context (`/horizon`, `/telescope`, any central-route policy) it queried `saas_central.permissions` and died with a QueryException instead of denying. `register_permission_check_method` is off; `AppServiceProvider` registers a tenancy-aware replacement that only consults permissions when a tenant is active. |
| **Horizon/Telescope gated on `is_super_admin`** | Both dashboards expose data across every organization. Laravel's scaffold gates them on a hardcoded email list (empty by default — safe but unusable, and an invitation to paste emails in later). The platform-admin flag already models exactly this. |
| **Recurring events stored as a rule, not rows** | A "forever" series has no finite row set, and materialising one makes "edit all future" a mass rewrite. Occurrences are expanded on demand for a bounded (max 1 year) window; only exceptions to a series get a row. |
| **Audit log & notifications live in the tenant DB** | Each org's trail and each user's per-org inbox are naturally isolated. Required tenant-pinned models (Activity, DatabaseNotification) — the same connection trap as Role — and converting Spatie's *named-class* activity_log migrations to anonymous classes, since tenant migrations run once per tenant in one process and a named class can't be redeclared. |
| **Webhooks are signed, queued, and circuit-broken** | HMAC-SHA256 over the exact body so a receiver can prove authenticity; queued so the triggering request never blocks on a slow endpoint; auto-paused after N consecutive failures so a dead receiver stops consuming workers. |
| **Share tokens hashed; org slug in the public URL** | A leaked backup must not yield working links. And a public visitor sends no header, so the org slug in the path is the only thing that says which tenant DB holds the share. |
| **`routes/tenant.php` emptied** | The stancl scaffold registers a domain-identified `/` that collides with the app root and 500s for any non-tenant host. We identify tenants by header, not domain, so it is dead weight. |
| **Tenancy is ended when the request terminates** | The tenant-init middleware now reverts to central context in `terminate()`. Without it the swapped connection outlives the request — invisible on a per-process worker, but under Octane (and in the test harness, which reuses the container) the next request would start with a previous org's database as default. The fourth variant of the string-table-name cross-DB trap. |
| **Kanban ordering uses float positions** | A card dropped between two others takes the midpoint of its neighbours, so a drag writes one row instead of renumbering the column. Dropping at the top halves the first position rather than going negative. |
| **Soft-deleting a task cascades to subtasks** | The DB FK cascade only fires on hard delete. Without a model event, a soft-deleted parent would leave its subtasks behind — and they would resurface at the root of the board, their parent no longer nesting them. Restore reverses it. |
| **completed_at derived from status** | Set by a model event, never trusted from input, so a "done" task always has a completion date and a reopened one never does. |
| **One running timer per user, enforced in code** | MySQL has no partial unique index, so "at most one row per user with ended_at IS NULL" cannot be a DB constraint. Starting a timer stops any other. |
| **The organization is billable, not the user** | Someone in three organizations must not carry three payment methods, and a subscription must survive its purchaser leaving the company. `Tenant` gets Cashier's `Billable`; billing tables are central. |
| **Cashier's migrations were rewritten, not used as shipped** | They target `users` and declare `foreignId` — our billable is a UUID-keyed `Tenant`, which an unsigned bigint cannot hold. Cashier derives the FK from the billable, so the column is `tenant_id`. |
| **Cashier's columns are declared in `getCustomColumns()`** | stancl's VirtualColumn trait funnels undeclared attributes into the `data` JSON blob. Cashier would write `stripe_id` into JSON while querying it as a real column — silently never matching, re-creating a Stripe customer per request. |
| **Stripe price ids are the source of truth for money** | Local `*_amount` columns are display-only. Storing our own price would eventually disagree with what the customer is actually charged. |
| **`current_period_end` cached locally** | Cashier does not store it, so "renews on…" would otherwise require a live Stripe call on every billing page load — putting Stripe's uptime in the path of rendering a date. Kept fresh by webhook. |
| **`null` limit = unlimited, `0` = none** | Deliberately distinct. Conflating them silently grants Enterprise quotas. |
| **No plan falls back to Free, not unlimited** | Failing open would let anyone bypass billing by never subscribing. |
| **Limits enforced on create only** | Reads/updates/deletes must keep working at the ceiling, or a full organization could not delete rows to get back under it. |
| **402, not 403, at the limit** | It is not an authorization failure; clients should prompt an upgrade rather than say "access denied". |
| **Invitations live in the central DB** | The invitee may have no account yet, and the accept flow must resolve the token *before* any tenant context exists. Accept routes therefore sit outside the `tenant` middleware — it would reject a non-member before they could join. |
| **Invitation tokens stored hashed** | A leaked backup would otherwise hand over working invitation links. SHA-256, not bcrypt: the token is CSPRNG output with no guessable structure, and lookup must be one indexed query rather than a scan. |
| **Uploads use an extension deny-list** | The browser-reported MIME type is attacker-controlled, so `mimes:` alone is not a control. `.html`/`.svg` are included: served from our origin they are stored XSS. Stored paths are generated — the client filename is a display label only. |
| **A taggable cache store is mandatory** | `CacheTenancyBootstrapper` tags entries per tenant — that tagging is what keeps one organization from reading another's cached values. `file` and `database` cannot tag, and fail only at the moment something caches inside tenant context. `TenancyServiceProvider` now asserts this at boot rather than letting it surface as an opaque 500. Use redis/memcached (array in tests). |
| **`owner_id` / `user_id` carry no FK** | They reference central users; MySQL cannot enforce a foreign key across databases. Integrity is enforced in validation. |
| **Tests on MySQL, not SQLite** | The suite must exercise real `CREATE DATABASE` provisioning. Uses truncation, not transactions, because DDL implicitly commits in MySQL. |
