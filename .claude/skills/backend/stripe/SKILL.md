---
name: stripe
description: Billing/subscription standards for this Laravel app — Laravel Cashier ^16 with the organization (Tenant) as the Billable model, plan→Stripe price mapping, subscribe/swap/cancel/resume, and Cashier webhook reconciliation. Use when working on subscriptions, plans, billing, or Stripe webhooks.
---

# Stripe Billing (Cashier)

## Purpose

Manage organization subscriptions correctly on top of **Laravel Cashier ^16**.
Stripe is the source of truth for money and subscription state; the app never
computes an amount or infers a status locally. There is **no hand-rolled
StripeService, no Payment Intents/Checkout code, no manual refund flow** — Cashier
owns those. The root `backend` skill's tenancy rules override anything here.

## Scope

Subscription lifecycle and its webhook reconciliation for the headless API.
Central-DB concern: `plans` and `subscriptions` live central; the **Tenant is the
Billable** (`app/Models/Tenant.php:52` `use Billable`). Covers
`App\Services\BillingService`, `config/billing.php`, and
`App\Listeners\HandleStripeWebhook`. Generic API/error conventions are in `api`;
the webhook's forgery/signature story is in `security`. No accounting sync (no
Xero, no Payment Intents).

## Responsibilities

- Drive all subscription changes through `BillingService`
  (`subscribe`/`swap`/`cancel`/`resume`), never inline Cashier calls in controllers.
- Resolve the Stripe price id from plan+interval via config; fail loudly when it's null.
- Keep `tenants.plan_id` in step with Stripe (locally and via webhook).

## Best Practices

- **Tenant is Billable.** `$tenant->newSubscription(...)`, `$tenant->subscription('default')`.
  The Cashier subscription name is the fixed const `BillingService::SUBSCRIPTION = 'default'`
  (`app/Services/BillingService.php:25`) — single product, one name.
- **Plan → price id via config.** Prices come from `config('billing.prices')`
  (`config/billing.php:24`), which maps `plan.interval → env STRIPE_PRICE_*`.
  `Plan::priceIdFor($interval)` returns the id (`app/Models/Plan.php:70`); a plan's
  **display amount is separate** from its Stripe price id. `priceIdOrFail()` throws
  a `ValidationException` when the interval is invalid or the price id is null/empty
  (`app/Services/BillingService.php:180`) — a config gap surfaces as a clean 422, not
  an opaque Stripe rejection.
- **Lifecycle semantics are deliberate.** `swap` uses `swapAndInvoice` (bill proration
  now, not silently next month, `BillingService.php:88`). `cancel` cancels at period end
  — the paid-through window is the grace period. `resume` only works `onGracePeriod()`.
  `activeSubscription` treats `valid()` (active/trialing/grace) as active.
- **Trials don't restart.** Switching mid-trial preserves the remaining trial; an org
  that already subscribed once gets `skipTrial()` (`BillingService.php:45`).
- **Webhooks reconcile, they don't create.** Cashier maintains the `subscriptions`
  table and owns the webhook route; `App\Listeners\HandleStripeWebhook` (wired in
  `app/Providers/AppServiceProvider.php:41` on Cashier's `WebhookReceived`) only
  reconciles `tenants.plan_id` and the cached `current_period_end`. It handles
  `customer.subscription.{created,updated,deleted}`; a deleted subscription falls the
  org back to the free tier. Tolerate a missing local row (the webhook can arrive
  before Cashier writes it) — return quietly, never throw inside a webhook.
- **Stripe is authoritative on price.** Re-derive the plan pointer from the webhook's
  price id rather than trusting the local value (a plan can be changed in Stripe's
  dashboard) — see `HandleStripeWebhook::syncSubscription`.

## Coding Standards

- Keys/prices from `config()` backed by `.env` (`config/billing.php`,
  `config/cashier.php`); never hardcode. Separate test/live price ids per environment.
- Thin controller → `BillingService`; validate input via Form Request; Cashier failures
  become 422s through `callStripe()` (`BillingService.php:223`) — genuine bugs still 500.
- Log plan changes (ids/status), never card data or secrets.

## Performance Guidelines

- Keep webhook handlers light; they run in-request. Push heavy follow-up work to a
  queued Job (see `queues`).
- `tenants.plan_id` exists so per-request limit checks read a local pointer instead
  of calling Stripe — keep it accurate rather than querying Stripe on the hot path.

## Security Considerations

- **Never handle raw card data** — Cashier/Stripe Elements tokenize client-side.
- The webhook route is verified by **Stripe signature** (Cashier owns this).
  `stripe/*` is excluded from `preventRequestForgery` in `bootstrap/app.php:44` —
  **never broaden that exclusion** and never disable signature verification (see `security`).
- Secrets stay in `.env`/secret store; rotate on exposure.

## Common Mistakes

- Reaching for a `StripeService`/Payment Intents/manual refunds — none exist; use Cashier via `BillingService`.
- Reading `STRIPE_PRICE_*` with `env()` outside `config/` — returns null once `config:cache` runs; read `config('billing.prices')`.
- Assuming the plan's display amount is the Stripe price — they're separate fields.
- Throwing inside the webhook when the local subscription row isn't there yet.
- Trusting the local plan over the webhook's price id.
- Broadening the `stripe/*` forgery exclusion.

## Recommended Workflow

1. Change subscriptions only through `BillingService`; add the price mapping to
   `config/billing.php` + the matching `STRIPE_PRICE_*` env var.
2. Validate plan/interval in a Form Request; let `priceIdOrFail()` guard the config gap.
3. For dashboard-side changes, extend `HandleStripeWebhook` and keep `tenants.plan_id`/period end reconciled.
4. Test with `Event`/mocked Cashier events; assert `tenants.plan_id` and subscription state.
5. Run the quality gates (`pint`, `phpstan`, `pest`, `l5-swagger:generate`).

## Output Expectations

Subscription changes flow through `BillingService`; prices resolve from config and
fail loudly when null; webhooks reconcile `tenants.plan_id`/period end idempotently;
the signature-verified `stripe/*` route stays narrow; no card data or secrets logged.
**Stripe/Cashier paths are unverified against live Stripe — there are no keys** — so
state that assumption when touching them. Files referenced as `path:line`.
