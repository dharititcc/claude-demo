---
name: database
description: Schema, migrations, Eloquent modeling, and query correctness for this database-per-tenant Laravel app — central vs tenant connections, connection-pinning traits, tenant migrations as anonymous classes, indexing, decimal money, and eager loading. Use when writing migrations, designing tables, adding relationships, or reviewing any data-layer change.
---

# Database & Eloquent

## Purpose

Keep the data layer correct under **database-per-tenant** multi-tenancy, where the
defining bug is a query on the wrong connection — a silent cross-tenant read, not
a thrown error. The root `backend` skill's tenancy rules override anything here.

## Scope

MySQL 8 schema and all Eloquent interaction: `database/migrations/` (central),
`database/migrations/tenant/` (per tenant), `database/seeders/`, `app/Models/`,
and the one query builder `app/Repositories/CustomerRepository.php`. Caching is in
`performance`; queues in `queues`.

## Responsibilities

- Put every table in the right database and pin every model to its connection.
- Design normalized, indexed schemas with new (never edited) migrations.
- Model relationships accurately and eager-load them (`preventLazyLoading` is on).

## Best Practices

- **Central vs tenant split.** Central DB: `tenants`, `users`, `plans`,
  `subscriptions`, `invitations`, `login_histories`, `admin_activities`,
  `organization_stats`. Tenant DB (`tenant_<uuid>`): `customers`, `projects`,
  `tasks`, `events`, `files`, `file_shares`, `notifications`, `roles`,
  `permissions`, `activity_log`. Know which one your table belongs to before
  writing the migration.
- **Pin the connection.** Every model uses
  `App\Models\Concerns\UsesCentralConnection` or `UsesTenantConnection` (e.g.
  `Customer.php:53`). An unpinned model inherits the parent's connection via
  `newRelatedInstance()` — the root cause of most tenancy incidents. Not pinning
  is the bug.
- **Migrations are the source of truth** — never edit the DB by hand. Central
  migrations in `database/migrations/`; tenant migrations in
  `database/migrations/tenant/` and they **must be anonymous classes**
  (`return new class extends Migration`) — named classes cannot run per tenant.
- **Deploys run both** `php artisan migrate` **and** `php artisan tenants:migrate`.
- **Add a column with a NEW migration.** Editing an already-run migration needs a
  destructive `migrate:fresh` — never do that to change schema.
- **Foreign keys + indexes:** `foreignId()->constrained()` for relationships;
  index every column used in `WHERE`, `JOIN`, `ORDER BY`, or as a foreign key.
  Composite indexes in the column order queried.
- **Correct column types:** `decimal` for money (never `float`),
  `unsignedBigInteger` for ids, casts via `$casts`. Status values are backed by
  **PHP enums in `app/Enums/`** (`Role`, `Permission`) — there is no
  `config('enum')`/`config/enum.php`.
- **Relationships over manual joins:** `hasMany`/`belongsTo`/`belongsToMany`,
  `withCount`, `whereHas`. No tenant `where`-scoping is needed — the model already
  resolves to the active tenant's DB (`CustomerRepository.php:15-18`).
- **Mass-assignment safety:** define `$fillable`; never feed `$request->all()`.
- **Audit** is tenant-scoped via `spatie/laravel-activitylog` (`App\Models\Activity`,
  `UsesTenantConnection`) through the `Auditable` trait (`logOnlyDirty`). Central
  super-admin audit is the separate `App\Models\AdminActivity`.

## Coding Standards

- Migration file names describe intent (`create_customers_table`,
  `add_status_index_to_projects_table`).
- Schema builder fluently; avoid raw `DB::statement` unless a feature requires it,
  and comment why.
- Query building lives in `CustomerRepository` (the only repository) or the model,
  never in controllers. Model query methods return builders/collections, never
  HTTP data.
- Match sibling migration/model style.

## Performance Guidelines

- **Kill N+1:** eager-load with `with()`/`load()`. `preventLazyLoading` is ON
  (`AppServiceProvider.php:138`), so a lazy load **throws** in dev/test — a missed
  eager load fails loudly, not silently.
- **Select narrowly** for lists/exports; paginate. `CustomerRepository` offers
  `paginate()` and `cursorPaginate()` (cursor for large exports — O(1) as offset
  grows).
- **Chunk large sets:** `chunkById()`/`lazy()`/`cursor()` for batch jobs; never
  `->get()` an unbounded table.
- **Push filtering to SQL** and aggregate in the DB (`count`/`sum`/`withCount`),
  not in PHP.
- Wrap multi-statement writes in `DB::transaction()` at the Service boundary.

## Security Considerations

- **Never build SQL from user input by concatenation** — Eloquent/builder
  parameterize; any `DB::raw` uses bindings.
- Cross-tenant `exists:` rules (e.g. against `plans`) must be **qualified with the
  central connection** or they run on the tenant DB and 500/misvalidate.
- Guard mass assignment via `$fillable`.
- Soft-delete records with history (`Customer` uses `SoftDeletes`) rather than
  hard-deleting.

## Common Mistakes

- Adding a model without a connection trait → cross-tenant leak or
  "table doesn't exist" far from the cause.
- A tenant migration written as a named class → won't run per tenant.
- Editing an already-run migration instead of adding a new one.
- Forgetting `tenants:migrate` on deploy, so tenant schemas drift from central.
- Missing FK/filter indexes; `float` for currency.
- A missed eager load → thrown lazy-loading violation.
- Reaching for `config('enum.*')`, an `old_mysql` connection, or a `BaseRepository`
  — none exist here.

## Recommended Workflow

1. Decide the database: central or tenant? Pick the migration folder accordingly.
2. Write the migration (schema + FKs + indexes + `down()`); tenant ones as
   anonymous classes.
3. Run `php artisan migrate` and, for tenant tables, `php artisan tenants:migrate`.
4. Update the model: connection trait, `$fillable`, `$casts`, relationships.
5. Add/adjust `CustomerRepository` (or model) query methods with eager loads.
6. Add a test (inside `$tenant->run()` for tenant data); run the quality gates.

## Output Expectations

Migrations land in the correct folder (tenant ones anonymous), are indexed and
reversible; models are connection-pinned; money is `decimal`; status uses
`app/Enums/`; queries are eager-loaded and narrowly selected. Deploy note states
whether `tenants:migrate` is needed. Changes reference files as `path:line`.
