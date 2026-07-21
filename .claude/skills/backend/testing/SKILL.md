---
name: testing
description: Testing standards for this Laravel multi-tenant API — Pest 4, real MySQL with DatabaseTruncation (not SQLite/RefreshDatabase), the tests/Pest.php helpers, per-tenant provisioning, guard caching, and the quality gates. Use when writing tests, adding a feature that needs coverage, or reviewing test quality.
---

# Testing

## Purpose

Prove features work — and, above all, that tenant isolation holds — with a suite
that stays green as the code evolves. This skill defines what to test, how to
structure it, and the bar a change meets before it ships. The root `backend`
skill's tenancy rules override anything here.

## Scope

Automated tests under `tests/` (Feature and Unit) run with **Pest 4** via
`./vendor/bin/pest` from `backend/`. Covers HTTP endpoints, services,
repositories, and validation. Config is pinned in `phpunit.xml`
(`DB_CONNECTION=mysql`, `DB_DATABASE=saas_central_test`,
`TENANT_DB_PREFIX=testtenant_`) — **not `.env.testing`**.

## Responsibilities

- Cover business logic (Services) and critical paths (auth, tenancy, permissions,
  quotas, money).
- Prove tenant isolation — the defining bug class here is a cross-tenant read.
- Keep tests deterministic; mock at the service boundary, never hit real APIs.

## Best Practices

- **Pest, not PHPUnit-as-runner:** `it()`/`test()` closures, `uses()` in
  `tests/Pest.php`. Feature tests get `uses(TestCase::class, DatabaseTruncation::class)`.
- **Real MySQL, on purpose.** SQLite cannot prove database-per-tenant isolation.
  Each test provisions a real tenant DB (~118 DDL statements), so ~171 tests take
  ~47 min. That cost is the point — do not "fix" it by faking the database.
- **`DatabaseTruncation`, not `RefreshDatabase`:** provisioning a tenant issues
  `CREATE DATABASE`; DDL implicitly commits in MySQL and would silently break
  `RefreshDatabase`'s transaction rollback (`tests/Pest.php:16-24`).
- **Use the helpers** in `tests/Pest.php`: `registerUser()` (returns
  `[user, tenant, token]`), `orgHeaders()`, `apiAs()`, `giveRole()`,
  `inviteToOrg()`, `usingRedisCache()`, `dropTestTenantDatabases()`.
- **Auth via `apiAs($token, $tenant)`** — Sanctum bearer + `X-Organization`
  header. When a test acts as more than one user, call
  `app('auth')->forgetGuards()` (or just use `apiAs()`, which does it): the guard
  caches the first user it resolves, so a later request silently keeps acting as
  the earlier one (`tests/Pest.php:118-128`).
- **Assert tenant data inside `$tenant->run(...)`** — outside it, the query runs
  on the wrong connection and finds nothing (or another tenant's rows).
- **Cache-isolation tests** call `usingRedisCache()`; the default array store is
  replaced on every tenancy bootstrap and can't prove isolation.
- **Factories over manual inserts;** one behavior per test; descriptive names.

## Coding Standards

- Test names describe behavior, not implementation.
- No conditional logic or randomness; data explicit and deterministic.
- Assert outcomes — HTTP status, response body, DB rows (inside `$tenant->run`),
  dispatched events — not internal call order unless it matters.
- Match the sibling test's structure and helper usage.

## Performance Guidelines

- Per-tenant provisioning dominates runtime; there is no cheap fix. Prefer Unit
  tests for pure logic so they skip tenant setup.
- **Do not use `--parallel`** — tenant provisioning is shared and races.
- Never edit PHP, move migrations, or `composer require` while the suite runs; it
  invalidates the run.

## Security Considerations

- Every protected endpoint gets a denial case: 403 without the Spatie permission,
  400 without `X-Organization`, 402 on a plan/quota breach.
- Exercise the permission matrix with `giveRole()` across the `App\Enums\Role`
  spread (owner/admin/manager/employee/viewer).
- Add a cross-tenant leakage test where isolation matters: user A must not read
  tenant B's rows.
- Never put real secrets in tests; config comes from `phpunit.xml`.

## Common Mistakes

- Reaching for `RefreshDatabase`, SQLite, `.env.testing`, or `--parallel` — all
  wrong here.
- Acting as a second user without `forgetGuards()` → the test silently keeps the
  first user.
- Asserting tenant data outside `$tenant->run()` → false negatives.
- Only happy-path coverage; missing the 403/400/402 denials.
- Trying to test Stripe/Cashier billing end to end — those paths are unverified
  (no keys); mock at the service boundary instead.

## Recommended Workflow

1. Register a tenant with `registerUser()`; grab `[user, tenant, token]`.
2. Drive the endpoint with `apiAs($token, $tenant)`; assert status + envelope.
3. Assert side effects inside `$tenant->run(...)`.
4. Add the authorization denial (`giveRole()` to a weaker role → 403) and a
   validation failure (422).
5. Run `./vendor/bin/pest`; fix until green.

## Output Expectations

New behavior ships with Pest tests covering happy path, authorization denial, and
validation failure; tenant assertions run inside `$tenant->run()`; external
billing is mocked, not hit. All quality gates pass: `pint --dirty`,
`phpstan analyse` (level 6), `pest`, `l5-swagger:generate`. Coverage is **never
measured locally** (no driver — pcov is CI-only); do not claim a number. Tests
reference the code under test as `path:line`.
