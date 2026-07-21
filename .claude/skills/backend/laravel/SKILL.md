---
name: laravel
description: Core Laravel 13 / PHP 8.3 engineering standards for this codebase — layered architecture (Controller → Form Request → Policy → Service/Repository → Model → API Resource), Eloquent, routing, and framework conventions for a headless multi-tenant JSON API. Use when writing or reviewing any Laravel feature, controller, service, model, or route.
---

# Laravel Engineering Standard

## Purpose

Define how Laravel code is written, structured, and reviewed in this project so
every feature is consistent, testable, and safe under database-per-tenant
multi-tenancy. This is the baseline skill; domain skills (database, security,
api, queues, spatie) build on it. The root `backend` skill's tenancy rules
override anything here.

## Scope

All PHP application code under `backend/app/`, `routes/`, `config/`. Targets
**Laravel 13, PHP 8.3+, MySQL 8**, Redis, Sanctum, Spatie Permission,
`stancl/tenancy` v3, Cashier, Pest. The backend is a **headless JSON API** — no
Blade UI (the client is a separate React SPA). Infrastructure is in `devops`,
`docker`, `linux-server`.

## Responsibilities

- Keep controllers thin: HTTP concerns, `$this->authorize()`, delegation, and
  returning an API Resource.
- Put validation in Form Requests (or inline `validate()` for small actions),
  business logic in Services, and the one read-heavy query builder in
  `CustomerRepository`.
- Pin every model to its database connection (central vs tenant).

## Best Practices

- **Layered flow:** `Controller` → `Form Request` (validate) →
  `$this->authorize()` (Policy) → `Service` (writes) / `Repository` (customer
  reads) → `Model` → `App\Http\Resources\*` (JSON). Wrap writes in
  `DB::transaction`.
- **Response envelope:** return `new XResource(...)` or
  `XResource::collection(...)`, and for writes wrap in `{message, data}` with the
  right status (201 create, 200 update, 402 on a plan/quota breach — the caller
  is permitted, the plan is not).
- **Authorize explicitly:** `$this->authorize('update', $model)` per action.
  **Never `authorizeResource()`** — Laravel 11+ removed controller middleware
  from the base controller; it is fatal here.
- **Dependency injection:** type-hint dependencies in constructors; let the
  container resolve them. Don't `new Service()` inside a controller.
- **Route model binding:** implicit binding (`show(Customer $customer)`) works
  because `SubstituteBindings` is prepended **after** `InitializeTenancyForUser`
  in `bootstrap/app.php` — so `{customer}` resolves against the tenant DB, not
  central. Don't reorder that.
- **Eloquent, not raw SQL:** relationships, scopes, collections. Drop to the
  query builder only for measured wins, and document why.
- **Events for side effects:** emit domain events through `EventDispatcher`
  (e.g. `customer.created`) and use listeners/observers — don't inline mail,
  webhooks, or audit into the write path.
- **Roles/permissions come from enums:** `App\Enums\Role` / `App\Enums\Permission`
  are authoritative; never hardcode role/permission strings (see `spatie`).

## Coding Standards

- **PSR-12**, enforced with `./vendor/bin/pint --dirty` before committing.
- **PHPStan level 6** must be `[OK] No errors`.
- Strict, explicit typing: typed properties, parameter/return types, PHP 8.3
  enums/readonly/promotion where they add clarity.
- Match sibling-file style in the same domain — naming, folder layout, idioms
  indistinguishable from existing code.
- Services in `app/Services/{,Admin/}`; requests in
  `app/Http/Requests/{Domain}/`; resources in `app/Http/Resources/`.

## Performance Guidelines

- Eager-load to avoid N+1 — `preventLazyLoading` is on, so a lazy load throws in
  dev/test. Verify with Telescope (see `performance`).
- Select only needed columns for list endpoints; paginate.
- Push slow/external work (webhooks, stats refresh, Stripe sync) into Queue Jobs.
- Cache with `Cache::remember` on a **taggable** store (Redis); tenancy tags
  entries per tenant. `file`/`array` cache cannot tag and the app refuses to boot
  on them.

## Security Considerations

- Validate every request; control mass-assignment via `$fillable`/`$guarded`;
  never pass `$request->all()` blindly. Cross-tenant `exists:` rules must be
  qualified with the **central** connection.
- Authorize with Policies + Spatie permissions (tenant-scoped). Super Admin is a
  central boolean via `Gate::before`, not a role.
- `env()` only inside `config/` files (breaks config caching otherwise).
- See the `security` skill for the full model.

## Common Mistakes

- Adding a model without a connection trait → cross-tenant leak or "table
  doesn't exist" far from the cause.
- `authorizeResource()` (fatal), or forgetting `$this->authorize()`.
- Hardcoded role/permission strings instead of the enums.
- Missing eager loads → thrown lazy-loading violation.
- Editing an already-run migration instead of adding a new one.
- Assuming Blade/Handlers/Repositories-for-everything — this is a Service-centric
  JSON API with a single `CustomerRepository`.

## Recommended Workflow

1. Read the root `backend` skill, then the sibling controller/service/resource.
2. Add/update the Form Request (or inline `validate()`); confirm the Policy.
3. Implement logic in the Service (transaction + events); pin new models.
4. Wire the controller: `authorize()`, delegate, return a Resource envelope.
5. Annotate the endpoint with `#[OA\...]` (see the root skill's OpenAPI rules).
6. Run `pint --dirty`, `phpstan analyse`, `pest`, `l5-swagger:generate`; add/adjust
   tests (see `testing`).

## Output Expectations

Code passes Pint + PHPStan level 6 + existing tests, follows the layered flow,
uses the correct DB connection, returns a Resource in the standard envelope, and
changes only the files the task requires. Explanations cite files as
`path:line`.
