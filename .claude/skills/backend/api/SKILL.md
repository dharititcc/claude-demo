---
name: api
description: REST API design for this headless multi-tenant Laravel app — /api/v1 versioning, Sanctum bearer + X-Organization tenancy, FormRequest validation, JsonResource output with an inline {message,data} envelope, the error taxonomy (422/402/400/403/401/409), OpenAPI attributes, and rate limiting. Use when building or reviewing endpoints, resources, or the JSON contract.
---

# REST API

## Purpose

Give the React SPA a predictable, secure JSON contract. Every endpoint is
versioned, tenant-scoped, authorized, validated, and serialized the same way. The
root `backend` skill's tenancy rules override anything here.

## Scope

HTTP JSON endpoints in `routes/api.php` (+ `routes/tenant.php`), API Resources in
`app/Http/Resources/`, FormRequest validation, auth/authorization, pagination,
error shapes, OpenAPI, and rate limiting. The API is **headless** — the UI is a
separate React SPA; there is no Blade output.

## Responsibilities

- Design resource-oriented endpoints with correct verbs and status codes.
- Serialize output through `JsonResource`, never raw models.
- Validate via FormRequests and authorize every action.
- Return the consistent success/error envelope with the right status.

## Best Practices

- **Versioned routing:** everything under `Route::prefix('v1')` → `/api/v1/...`
  (`routes/api.php:51`). Controllers live in `App\Http\Controllers\Api\V1`.
- **Auth = Sanctum bearer + `X-Organization`.** The bearer token (central-pinned
  `PersonalAccessToken`) authenticates the user; the `X-Organization` header
  (slug or UUID) selects the tenant DB via the `tenant` middleware. No session
  auth, no CSRF-cookie flow.
- **JsonResource output** for all bodies — never return an Eloquent model
  directly (leaks columns, breaks on schema change). Shape relationships in the
  Resource; eager-load them first.
- **The envelope is built inline** with `response()->json([...], $status)` — there
  is **no `ResponseHelper` and no `app/Helpers`**. Writes return
  `{message, data}` (`CustomerController.php:113,152,176`); reads return
  `{data: ...}` or a paginated `JsonResource` collection.
- **FormRequest validation** on every action — reads and writes. Every endpoint
  type-hints an `app/Http/Requests/{Domain}/*Request`; there is **no** inline
  `$request->validate()` in any controller (index/filter actions use an
  `Index*Request`). Returns 422 with field errors.
- **Error taxonomy:** 422 validation · 402 plan/quota breach (caller permitted,
  plan is not — `EnforcePlanLimit.php:52`) · 400 missing `X-Organization`
  (`InitializeTenancyForUser.php:33`) · 403 non-member or policy denial · 401 bad
  token · 409 conflict.
- **Route model binding** resolves against the **tenant** DB because
  `SubstituteBindings` runs after tenancy — `show(Customer $customer)` 404s on a
  miss automatically. Don't reorder that.
- **Pagination:** paginate lists (`CustomerRepository::paginate/cursorPaginate`);
  never dump unbounded collections.
- **Webhooks, two kinds:** inbound Stripe via Cashier (`WebhookReceived` →
  `HandleStripeWebhook`; `stripe/*` excluded from `preventRequestForgery`, verified
  by signature) vs the **outbound** webhook-endpoints CRUD feature
  (`routes/api.php:253-257`, `WebhookController`) that fans domain events out to
  subscriber URLs.

## Coding Standards

- Thin controllers: `authorize()` → delegate to a Service → return a Resource.
  No business logic inline.
- Explicit status codes; never 200 for an error.
- JSON keys snake_case, matching existing resources.
- **Annotate every endpoint with `#[OA\...]` attributes** on the controller
  method; run `php artisan l5-swagger:generate`. Shared schemas live in
  `app/OpenApi/` as one `#[OA\Schema]` holder class each
  (`CustomerSchema`, `ValidationErrorSchema`, `OrganizationHeaderParameter`, ...).

## Performance Guidelines

- Eager-load relations a Resource touches (`preventLazyLoading` is on — a lazy
  load throws).
- Select narrow columns; support filtering on large payloads.
- Rate-limit: default `throttleApi()` (60/min, `bootstrap/app.php:60`); auth
  endpoints tighter (`throttle:6,1`/`10,1`); public shares 30/min
  (`routes/api.php:91`).
- Queue heavy webhook delivery; acknowledge fast.

## Security Considerations

- Authenticate and authorize every endpoint (`$this->authorize()` + Policy +
  Spatie permission). Fail closed. See `security`.
- Validate and mass-assignment-control all input; never `$request->all()`.
- Cross-tenant `exists:` rules must be central-qualified.
- Public file shares (`PublicShareController`) are unauthenticated — enforce token
  validity, expiry, and download caps server-side; throttled 30/min.
- Verify the Stripe webhook signature; never broaden the `stripe/*` forgery
  exclusion.

## Common Mistakes

- Returning a raw model instead of a Resource.
- Inventing a `ResponseHelper`/`app/Helpers` — the envelope is inline.
- Missing `authorize()`, or using `authorizeResource()` (fatal here).
- Wrong status: 403 for a quota breach that should be 402, or 500 where the
  missing header should be 400.
- Documenting a path that isn't a real route (verify both directions vs
  `route:list` after `l5-swagger:generate`).
- Assuming Hashids/session auth/Xero webhooks — none exist.

## Recommended Workflow

1. Define the resource, `/api/v1` route, verb, and status codes.
2. Add the action's FormRequest (`app/Http/Requests/{Domain}/`); confirm the Policy/permission.
3. Implement the Service; keep the controller thin.
4. Build the `JsonResource` with eager-loaded relations.
5. Return the inline `{message,data}` envelope with the right status; paginate
   lists.
6. Add `#[OA\...]`, run `l5-swagger:generate`, verify against `route:list`; add
   tests (200, 422, 403, 402/400 where relevant).

## Output Expectations

Endpoints are `/api/v1`-versioned, Sanctum + `X-Organization` scoped, validated,
authorized, and serialized via Resources with the inline `{message,data}`
envelope and correct status codes. OpenAPI attributes match real routes both
ways. Files referenced as `path:line`.
