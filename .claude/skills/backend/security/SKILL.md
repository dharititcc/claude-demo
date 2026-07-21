---
name: security
description: Application security standards for this Laravel multi-tenant API — tenant isolation, Sanctum auth, Spatie permissions/policies, Super Admin gate, input validation, mass-assignment, request-forgery/Stripe webhooks, public file shares, 2FA, impersonation, and secrets. Use when handling user input, auth, tenancy, file sharing, or reviewing any change for security.
---

# Security

## Purpose

Protect tenant data, financial records, and platform integrity by making
secure-by-default the norm. In this app the **primary security boundary is
tenant isolation** — a query on the wrong database connection is a data breach,
not just a bug.

## Scope

Application security for all HTTP entry points, jobs, and integrations of the
Laravel API. Covers tenant isolation, authn/authz, validation, injection
defenses, public shares, 2FA, impersonation, and secrets. Server hardening is in
`linux-server`; error monitoring/PII is in `sentry`; roles/permissions detail is
in `spatie`.

## Responsibilities

- Keep every query on the correct database (central vs tenant).
- Authenticate (Sanctum) and authorize (membership + Spatie permissions +
  policies) every request.
- Validate and mass-assignment-control all external input.
- Guard the unauthenticated surfaces (public shares, Stripe webhooks).

## Best Practices

- **Tenant isolation first.** Models pin their connection
  (`UsesCentralConnection`/`UsesTenantConnection`). The `tenant` middleware
  (`InitializeTenancyForUser`) boots the org DB from the `X-Organization` header
  and verifies membership; missing header → 400, non-member → 403. Never widen a
  query to "find across orgs" without a super-admin path.
- **Auth = Sanctum bearer tokens** resolved against the **central** DB (custom
  `PersonalAccessToken` pinned central). No CSRF-cookie SPA flow.
- **Authorization on every action:** `$this->authorize('ability', $model)` +
  Policies, backed by Spatie tenant permissions (`App\Enums\Permission`). Copy
  the sibling-controller pattern; never leave an endpoint unguarded. Fail closed.
- **Super Admin** is `users.is_super_admin` (central boolean) via `Gate::before`
  — a full bypass. Guard super-admin routes with the `super-admin` middleware,
  which returns **404** (not 403) to non-admins so the surface isn't advertised.
- **Validate via Form Requests** (one per action; no inline `validate()`), with strict rules.
  Cross-tenant `exists:` (e.g. `owner_id`, `plans`) **must be qualified with the
  central connection** or it runs against the tenant DB and 500s/misvalidates.
- **Mass-assignment control:** define `$fillable`; never feed `$request->all()`
  into `create()`/`update()`.
- **Parameterized queries only:** Eloquent/builder bindings; never concatenate
  input into `DB::raw`.
- **Public file shares** (`FileShare`): the token is `Str::random(40)`, stored
  **hashed** (`hash('sha256', …)`), never in plaintext. `PublicShareController`
  is unauthenticated and resolves tenancy from the **URL slug**; enforce
  `expires_at`, `max_downloads`, and optional password (`Hash::check` against
  `password_hash`, sent in the POST body). Throttled 30/min. Treat the token as
  untrusted and re-check validity server-side.
- **Request forgery:** Laravel 13 `preventRequestForgery()`; `stripe/*` (Cashier
  webhooks) are excluded and instead verified by **Stripe signature** — never
  broaden that exclusion.
- **2FA (TOTP):** a correct password with 2FA enabled is **not** a session — it
  returns a challenge; only the completed challenge issues a token. Guard the
  replay window (a used TOTP window must not be reusable).
- **Impersonation:** super admins mint a tagged, short-lived Sanctum token on the
  target user (`impersonator_id`, `impersonated_tenant_id`, per-token
  `expires_at`); the token is org-scoped. Never impersonate a super admin/self.
- **Secrets:** read from `config()` backed by `.env`; never hardcode or commit
  keys; rotate on exposure.

## Coding Standards

- No inline auth logic — centralize in Policies/middleware/`Gate::before`.
- Fail closed: deny by default, allow explicitly.
- Audit meaningful actions (tenant `activity_log`, central `admin_activities`);
  never log secrets, tokens, or full PII (see `spatie`).

## Performance Guidelines

- Rate-limit auth, public, and expensive endpoints (`throttleApi`, 60/min
  default; public shares 30/min) to blunt brute-force/DoS.
- Reset the Spatie permission cache after role changes (`ForgetCachedPermissions`
  listener) so a revoked grant doesn't linger.

## Security Considerations

- Enforce HTTPS in production; secure/httponly cookies where relevant.
- Keep dependencies patched; run `composer audit`.
- Scrub PII/secrets from Sentry payloads (see `sentry`).
- Least privilege for DB users, queue workers, and API tokens.
- `login_histories` known gap: a password-correct-but-2FA-incomplete attempt is
  currently recorded as successful — treat with care until fixed.

## Common Mistakes

- A query on the wrong connection → cross-tenant read (the worst bug here).
- Unqualified `exists:` rule running against the tenant DB.
- Missing `authorize()`/policy, or `authorizeResource()` (fatal).
- Trusting `$request->all()` → mass-assignment.
- Broadening the `stripe/*` forgery exclusion, or skipping Stripe signature
  verification.
- Storing a share token in plaintext, or skipping expiry/download-cap checks.
- Secrets in code, `.env` committed, or tokens in logs.

## Recommended Workflow

1. Identify the trust boundary and which database the data lives in.
2. Confirm Sanctum auth + tenant membership + the Policy/permission gate.
3. Add/confirm Form Request validation (central-qualified cross-tenant rules).
4. Ensure `$fillable` and parameterized queries throughout.
5. For public/webhook/2FA/impersonation flows, verify the specific control above.
6. Handle secrets via config; confirm nothing sensitive is logged; run
   `composer audit`; review with `code-review`.

## Output Expectations

Every endpoint is authenticated, tenant-scoped, authorized, and validated;
queries use the correct connection; unauthenticated surfaces enforce their
token/signature checks; secrets stay in `.env`/config. The change states the
trust boundary and controls applied, referencing files as `path:line`.
