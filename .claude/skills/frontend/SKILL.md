---
name: frontend
description: Use when working on the React 19 SPA in frontend/ — pages, components, hooks, services, stores, types, or Vitest tests. Covers the API contract with the Laravel backend, the form and query conventions, and the traps that fail silently rather than loudly.
---

# Frontend (React 19 + TypeScript + Vite)

Work from `frontend/`. React 19, TypeScript, Vite, React Router, TanStack Query,
Axios, Tailwind, shadcn-style UI in `src/components/ui/`, React Hook Form + Zod,
Zustand, Recharts, TanStack Table, react-hot-toast.

**The SPA consumes the REST API only.** No Blade, no server-rendered views, no
direct database access. If data is needed, an endpoint provides it.

## Layout

```
src/
  components/ui/    shared primitives (Button, Input, Card, Badge…)
  components/       feature components, grouped by domain (auth/, …)
  hooks/            useAuth, useCustomers… — TanStack Query wrappers
  pages/            route components
  services/         api.ts (Axios client) + one module per domain
  store/            Zustand (auth)
  types/            shared API types
```

## The API contract

`services/api.ts` owns the Axios instance: it attaches the bearer token and the
`X-Organization` header, and normalises errors. Always go through it.

**A 2xx is not always a success.** `POST /v1/auth/login` answers **202** when the
account has 2FA and still owes a code. Axios treats 202 as success, so reading
`data.data.token` off it stores `undefined` and leaves the app looking signed in
while every request 401s. This shipped once. `authService.login()` therefore
returns a discriminated union:

```ts
type LoginResult =
  | { status: 'authenticated'; session: LoginResponse['data'] }
  | { status: 'two_factor_required'; challengeToken: string }
```

Model "this call has more than one shape of success" as a union so callers are
forced to handle it, rather than reaching for a field that may not be there.

Other status codes worth handling deliberately:

- **402** — a plan/quota limit, not a permissions problem. Prompt to upgrade;
  do not say "forbidden".
- **403** — genuinely not allowed.
- **409** — state conflict (e.g. enrolling in 2FA that is already on).
- **429** — throttled; surface the wait, do not retry in a loop.

## Forms

Use React Hook Form + Zod via `zodResolver`.

**Every form needs `noValidate`.** Without it the browser's native constraint
validation (`type="email"`) blocks submit before RHF runs, showing an unstyled
native tooltip instead of our errors — the form appears to do nothing. This is
not optional styling; it is why the form works at all.

Label every input with `htmlFor`/`id` — tests query by label, and it is the
accessible baseline.

## Queries and mutations

TanStack Query for all server state; do not mirror it into Zustand.

- Query keys are arrays and must include what they vary on
  (`['context', activeOrgSlug]`). Organization-scoped data must include the org,
  or switching orgs serves the previous one's cache.
- Gate queries on preconditions with `enabled` so anonymous visitors do not fire
  guaranteed-401 requests.
- Mutations: `invalidateQueries` on success. Use `apiErrorMessage(error, ...)`
  from `hooks/useAuth` for user-facing failures, and toast them.

## Auth store (Zustand)

`store/auth.ts` holds user, organizations, active org slug, permissions.

- The token lives in its own storage key (`services/api`), **not** in the
  persisted store.
- Permissions are per-organization and are dropped on org switch — never
  persist them.
- `can(permission)` is a **UI hint only**, for hiding controls the user cannot
  use. The API re-authorizes every request. It is never the security boundary;
  do not treat a passing `can()` as authorization.

## Tests (Vitest + Testing Library)

```bash
npm run test          # vitest run
npm run typecheck     # tsc --noEmit
npm run lint          # oxlint
npm run build         # must succeed
```

All four must pass.

- Mock the service module (`vi.mock('@/services/auth', ...)`), not Axios.
- **Keep mock return shapes in sync with the real service.** A mock cast with
  `as never` will happily return a stale shape and the test passes while
  exercising a broken path — this happened when `login()` moved to a union.
- Query by role and label, not test ids.
- Assert what the user sees, and assert the payload sent to the service for
  anything security-relevant.

## Conventions

- Components: named files, default export for pages/feature components.
- Dark mode via Tailwind `dark:`; respect the existing tokens
  (`bg-muted`, `text-muted-foreground`, `border`) rather than raw colours.
- Lazy-load route components; keep code splitting intact.
- Comments explain constraints the code cannot show (why `noValidate`, why a
  202 is special) — not what the next line does.
