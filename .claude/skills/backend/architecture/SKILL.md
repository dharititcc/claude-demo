---
name: architecture
description: Layered architecture for this headless multi-tenant Laravel API — how Controllers, FormRequests, Policies, Services, the single CustomerRepository, Models, Resources, and domain events fit together and where new code belongs. Use when designing a feature, deciding which layer owns logic, or reviewing structural choices.
---

# Application Architecture

## Purpose

Give every contributor one mental model of how a request flows and a rule for
where each kind of logic lives, so the codebase stays navigable. This skill is the
arbiter when "which layer does this belong in?" comes up. The root `backend`
skill's tenancy rules override anything here.

## Scope

The end-to-end request/response and side-effect architecture across `app/`:
routing, HTTP layer, validation, authorization, business logic, persistence, and
domain events. Complements `laravel` (framework mechanics) and defers detail to
`database`, `api`, `queues`, `security`.

## Responsibilities

- Define the canonical layers and their boundaries.
- Decide ownership: which layer talks to which, and what each must never do.
- Preserve the real patterns — thin controllers, Services for writes, a single
  read Repository, connection-pinned models, events for side effects.

## The Layers

```
HTTP Request (JSON only — no Blade)
  → Route (routes/api.php, /api/v1 · routes/tenant.php)  ── binding, tenancy, throttle
  → Controller (app/Http/Controllers/Api/V1)  ── HTTP shape, $this->authorize(), delegate ONLY
  → FormRequest (app/Http/Requests/{Domain}/)  ── validation (one per action; no inline validate())
  → Policy                                      ── per-action authorization
  → Service (app/Services/{,Admin/})            ── business logic, DB::transaction, domain events
      → CustomerRepository (app/Repositories)   ── the ONE read-query builder
  → Model (connection-pinned)                   ── persistence
  → EventDispatcher → notifications/webhooks    ── side effects
  → JsonResource (app/Http/Resources)           ── output
```

**Dependency is one-way, top to bottom.** A Repository must not call a Service; a
Service must not build HTTP responses; a Resource must not run business logic.

## Layer Boundaries

- **Controllers** — HTTP concerns only: `$this->authorize('ability', $model)` per
  action, delegate to a Service, return a Resource in the inline `{message,data}`
  envelope. No business logic, no query building. **Never `authorizeResource()`**
  — it registers controller middleware removed in Laravel 11+ and is fatal here
  (`CustomerController.php:27-31`).
- **FormRequests** (`app/Http/Requests/{Domain}/`) own validation and
  request-level authorization. **Every** action type-hints one — index/filter
  actions included (`IndexCustomerRequest`). No controller contains a rule array;
  there is no inline `$request->validate()` anywhere.
- **Policies** — authorization logic, invoked via `$this->authorize()`; backed by
  Spatie tenant permissions (`App\Enums\Permission`). Never inline `if` checks.
- **Services** (`app/Services/{,Admin/}`) — the home of business logic and writes.
  Own `DB::transaction()` here and emit domain events through
  `App\Services\EventDispatcher` (e.g. `$this->events->dispatch('customer.created', ...)`,
  `CustomerService.php:29,45`). Coordinate the repository, enforce invariants.
- **CustomerRepository** (`app/Repositories/CustomerRepository.php`) — the **only**
  repository; it extends nothing. Encapsulates customer read queries
  (`paginate`/`cursorPaginate`/`query`). Most models are queried directly; do not
  invent a `BaseRepository` or a repository per model.
- **Models** — connection-pinned (`UsesCentralConnection`/`UsesTenantConnection`),
  `$fillable`, `$casts`, relationships. Audit via the `Auditable` trait.
- **EventDispatcher / listeners** — cross-cutting side effects (in-app
  notifications, outbound webhooks) so the write path stays linear. Inbound Stripe
  events go through Cashier's `HandleStripeWebhook` listener.
- **JsonResource** — output shape only.

## Best Practices

- Choose the layer by *responsibility*, not convenience. "Fewer files in the
  controller" is not a reason to skip the Service.
- Keep Services injectable; inject collaborators via the constructor, no
  `new Service()`.
- Model side effects as events so the primary write path reads linearly.
- Introduce a new pattern only with a documented reason — consistency over
  novelty. There is no Handler layer, no Observer directory, no `*TableController`.

## Coding Standards

- Single responsibility per class; DI via the container, not `app()` inside
  business logic.
- Business rules in Services, independent of HTTP; framework/IO at the edges.
- Match existing folders exactly: `app/Services/{,Admin/}`,
  `app/Http/Requests/{Domain}/`, `app/Http/Resources/`.

## Performance Guidelines

- `CustomerRepository` decides customer query shape (eager loads, columns);
  Services don't issue ad-hoc queries around it.
- One `DB::transaction()` per multi-write operation, at the Service boundary.
- `preventLazyLoading` is on — eager-load or it throws. Offload slow side effects
  to queued listeners (see `queues`).

## Security Considerations

- Authorization belongs in Policies/`$this->authorize()`, not scattered checks in
  Services.
- Super Admin is a central `users.is_super_admin` boolean via `Gate::before` — not
  a Spatie role, and not a `permission:` middleware in controllers.
- Every write path runs in tenant context; the model's connection trait keeps it
  on the right database. See `security`.

## Common Mistakes

- Business logic leaking into controllers or Resources.
- Referencing a Handlers layer, `ProjectInformationService`, `app/Observers`,
  `*TableController`/DataTables, `current_user()`, `permission:` middleware, or a
  derived/stored `project_status` — **none of these exist**.
- `authorizeResource()` (fatal) or a missing `$this->authorize()`.
- Building queries in the controller instead of the Service/`CustomerRepository`.
- A model added without a connection trait.

## Recommended Workflow

1. Sketch the flow through the layers; name the class you'll touch in each.
2. Classify the work: validation → FormRequest; business logic/write → Service;
   read-query building → (rarely) `CustomerRepository`; output shape → Resource;
   cross-cutting side effect → event/listener.
3. Reuse the module's existing Service/Resource; extend rather than duplicate.
4. Add side effects via `EventDispatcher`; queue the slow ones.
5. Review against boundaries: no layer doing another's job.

## Output Expectations

A change whose files map cleanly onto the layers — thin controller with per-action
`authorize()`, Service owning the transaction and events, connection-pinned model,
JsonResource output — with no boundary violations. Structural decisions are stated
explicitly and reference files as `path:line`.
