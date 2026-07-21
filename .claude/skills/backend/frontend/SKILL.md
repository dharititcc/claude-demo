---
name: frontend
description: The backend is a headless JSON API — it renders no application UI. This skill covers the backend's browser-facing surface (Swagger, Horizon, Telescope) and the API response contract the React SPA depends on. For actual UI work use the top-level `frontend` skill (the React 19 SPA in frontend/).
---

# Frontend-facing surface of the backend

## Purpose

Prevent the wrong mental model: **this Laravel app has no Blade UI, no Bootstrap,
no jQuery, no DataTables.** It is a headless JSON API consumed by a separate
React SPA. This skill documents the little the backend does serve to a browser,
and — more importantly — the **response contract** the SPA relies on, so backend
changes don't silently break the client.

## Scope

The backend's browser-facing pages and the API contract with the SPA. **Actual
UI development happens in the React app** (`frontend/`) — use the **top-level
`frontend` skill** for components, pages, hooks, stores, forms, and Vitest. This
skill is only about the backend side of that boundary.

## The only server-rendered pages

- **Swagger / OpenAPI docs** via `darkaonline/l5-swagger` — the API reference UI.
  Every endpoint is annotated with `#[OA\...]` attributes; regenerate with
  `php artisan l5-swagger:generate`.
- **Horizon** (queues) and **Telescope** (debugging) dashboards — dev/ops only,
  central context, auth-gated.
- `resources/views/welcome.blade.php` — a placeholder; not application UI.

There are **no Markdown mailables** (`app/Mail/` is empty). User-facing messages
go out as **Notifications** (`app/Notifications/` — `GenericNotification`,
`OrganizationInvitation`), not Blade emails.

## The contract the SPA depends on

The SPA (`frontend/`, React 19 + Vite + TanStack Query + axios) talks to the API
over two headers and a fixed response shape:

- **Auth:** `Authorization: Bearer <sanctum-token>` (no CSRF-cookie flow).
- **Tenant:** `X-Organization: <org-slug>` selects the active organization.
  Missing → 400, non-member → 403. Public share routes are the exception
  (tenancy from the URL slug, no token/header).
- **Response envelope:** resources are returned as `App\Http\Resources\*`
  (`JsonResource`), and write actions wrap them as `{ message, data }`. Keep this
  shape stable — the SPA's axios layer and types assume it.
- **Errors the SPA handles:** `422` (validation, with `errors` map), `402`
  (plan/quota breach), `400` (missing org header), `403` (not a member / policy
  denied), `401` (bad/expired token). Don't invent new shapes for these.
- **Pagination:** list endpoints return Laravel's paginator envelope; keep
  `data`/`meta`/`links` intact for the SPA's query hooks.

## Best Practices

- **Treat the response shape as an API, not an implementation detail.** Changing a
  Resource's fields, the envelope, or an error status is a breaking change for the
  SPA — coordinate it (update the OpenAPI annotations and the SPA types together).
- **Keep OpenAPI honest:** after `l5-swagger:generate`, verify both directions
  against `route:list` — no undocumented `/api/v1` route, no documented path that
  isn't real. The SPA and its Postman collection are generated from this spec.
- **Notifications, not emails:** add user-facing messages via a Notification
  channel, not a Blade mailable.
- **Don't add Blade UI here.** If a task seems to need a page, it belongs in the
  React SPA.

## Common Mistakes

- Assuming Blade/Bootstrap/jQuery/DataTables exist — they don't.
- Silently changing a Resource's fields or the `{message, data}` envelope and
  breaking the SPA.
- Returning a bespoke error shape instead of the standard `422/402/403/400`.
- Editing generated Swagger UI or the SPA build output instead of the annotations
  / the React source.
- Adding a Markdown mailable instead of a Notification.

## Recommended Workflow

1. Confirm the change is backend-only; UI changes go to the top-level `frontend`
   skill and the `frontend/` app.
2. Shape the JSON via an API Resource; keep the envelope and error statuses.
3. Update `#[OA\...]` annotations and run `l5-swagger:generate`; verify against
   `route:list`.
4. If the contract changed, update the SPA's TypeScript types and the Postman
   collection in the same change.

## Output Expectations

Backend changes preserve the SPA contract (headers, envelope, error statuses,
pagination) or coordinate a deliberate, documented break; OpenAPI stays in sync
with routes; user messaging uses Notifications. No Blade UI is added. Files
referenced as `path:line`.
