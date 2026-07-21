---
name: spatie
description: Spatie Permission (tenant-scoped roles/permissions) and Activity Log standards for this multi-tenant app — enum-defined permissions seeded per tenant, custom tenant-connection Role/Permission models, the Super Admin gate, permission-cache resets, and audit logging. Use when adding authorization, gating features, or recording auditable changes.
---

# Spatie Permission & Activity Log

## Purpose

Standardize authorization (roles/permissions) and audit logging using Spatie's
packages under **database-per-tenant** multi-tenancy, where roles and permissions
live in **each tenant's database**. Covers `spatie/laravel-permission` ^8 and
`spatie/laravel-activitylog` ^4.

## Scope

Role/permission definition, per-tenant seeding, enforcement (policies,
middleware, gates), and activity logging of tenant model changes. Complements
`security` (broader controls, Super Admin, tenancy) and `architecture` (where
authorization lives).

## Responsibilities

- Keep roles/permissions authoritative in enums and seeded per tenant.
- Enforce access via policies/middleware consistently, fail-closed.
- Log meaningful tenant activity without noise or PII leakage.

## Best Practices — Permissions

- **Definitions live in enums, not ad hoc:** `App\Enums\Role` (values `owner`,
  `admin`, `manager`, `employee`, `viewer`) and `App\Enums\Permission` (format
  `<resource>.<action>`, e.g. `customers.view`, `team.invite`, `billing.manage`,
  `audit.view`). Never create permissions at runtime; add them to the enum.
- **Seeded per tenant:** `database/seeders/TenantDatabaseSeeder.php` runs inside
  each freshly provisioned tenant DB (from `TenancyServiceProvider`). It
  `findOrCreate`s every permission and every role (guard `web`) and
  `syncPermissions(...)`. When you add a permission to the enum, it flows to new
  tenants automatically; **existing tenants need a re-seed/migration** to pick it
  up — don't assume it's everywhere.
- **Tenant-connection models:** `App\Models\Role` and `App\Models\Permission`
  extend the Spatie base models and add `UsesTenantConnection`, so they resolve
  against the active tenant DB. The **same user can be `owner` in one org and
  `viewer` in another** — never cache a role decision across orgs.
- **Grants:** `owner` = all; `admin` = all except `billing.manage`;
  `manager`/`employee`/`viewer` progressively narrower (`viewer` is read-only
  `*.view`). Mirror this when adding a permission.
- **Enforce in Policies + `authorize()`:** prefer Policies for record-level
  checks that reference the permission (`$user->can('customers.update')`); the
  root controllers call `$this->authorize(...)` per action, not
  `authorizeResource()`.
- **Super Admin is NOT a Spatie role:** it's `users.is_super_admin` (central) via
  `Gate::before` (see `security`). Don't model it as a role or permission.
- **Central-route safety:** `config/permission.php` sets
  `register_permission_check_method => false`; `AppServiceProvider` installs a
  tenancy-aware gate hook that returns `null` when tenancy isn't initialized, so
  Horizon/Telescope (central context) don't query a missing tenant `permissions`
  table. Don't re-enable the default hook.

## Best Practices — Activity Log

- **Package + tenant scoping:** `spatie/laravel-activitylog`; `App\Models\Activity`
  extends the Spatie model + `UsesTenantConnection`, so the `activity_log` table
  lives **in each tenant DB**.
- **Opt models in via the trait:** `App\Models\Concerns\Auditable` (`use
  LogsActivity`) — a model declares `protected array $auditable` (the attributes
  to track). It uses `logOnlyDirty()`, `dontSubmitEmptyLogs()`, and
  `useLogName($this->getTable())`. Extend that trait rather than adding parallel
  logging.
- **Read path:** `AuditLogController` (`GET /api/v1/audit-logs`), gated by the
  `audit.view` permission / policy; read-only, newest first.
- **Don't confuse it with the central admin audit:** platform-admin actions use a
  **separate, custom** `App\Models\AdminActivity` (central `admin_activities`,
  append-only) written via `App\Services\Admin\AdminAudit` — not Activity Log.
  Tenant changes → Activity Log; super-admin actions → AdminActivity.

## Coding Standards

- Reference permissions through `App\Enums\Permission` (typos silently
  misauthorize).
- Authorization in policies/middleware/`Gate::before`, never duplicated inline.
- Auditable attributes declared on the model; keep log names = table name.

## Performance Guidelines

- Rely on the Spatie permission cache; don't query permissions in tight loops.
- `logOnlyDirty()` keeps `activity_log` lean; prune if a tenant's log grows large.

## Security Considerations

- Fail closed: default deny. Never trust a client-side check.
- **Reset the permission cache after role/permission changes** — the
  `ForgetCachedPermissions` listener handles this; stale cache must not grant
  revoked access.
- Don't log secrets/PII in activity properties.
- Assign least privilege; review role→permission grants when adding features.

## Common Mistakes

- Creating permissions at runtime instead of adding them to the enum + re-seeding.
- Assuming a new enum permission exists in **already-provisioned** tenants.
- Caching a role/permission decision across organizations.
- Modeling Super Admin as a Spatie role.
- Re-enabling Spatie's default gate hook → central routes crash on the missing
  tenant `permissions` table.
- Confusing tenant Activity Log with central AdminActivity.

## Recommended Workflow

1. Add the permission to `App\Enums\Permission`; grant it to the right roles.
2. Ensure `TenantDatabaseSeeder` covers it; plan a re-seed for existing tenants.
3. Gate the action with a Policy + `$this->authorize(...)`.
4. Add/confirm `Auditable` coverage on the affected tenant model.
5. Reset the permission cache; test allowed and denied cases across two orgs
   (see `testing`).

## Output Expectations

Permissions are enum-defined and seeded per tenant; access is enforced via
policies (fail-closed) with cache reset after changes; tenant changes are
audit-logged via `Auditable` (no PII/secrets), super-admin actions via
`AdminAudit`. Tests cover allow/deny and cross-org role differences. Files
referenced as `path:line`.
