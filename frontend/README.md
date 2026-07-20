# Frontend — React 19 SPA

The single-page application for the multi-tenant SaaS platform. It consumes the Laravel REST API only — no server-side rendering, no Blade.

## Stack

React 19 · TypeScript · Vite 8 · React Router 7 · TanStack Query 5 · Zustand · React Hook Form + Zod · TailwindCSS 4 · Recharts · React Hot Toast · Vitest + Testing Library

## Getting started

```bash
npm install
cp .env.example .env     # point VITE_API_URL at the API
npm run dev              # http://localhost:5173
```

The API must be running (default `http://localhost:8000`). For sign-in-ready demo data, run `php artisan app:demo` in `../backend`.

## Scripts

| Script | What it does |
|---|---|
| `npm run dev` | Vite dev server with HMR |
| `npm run build` | Typecheck (`tsc -b`) then production build |
| `npm run typecheck` | Types only, no emit |
| `npm run lint` | oxlint |
| `npm run test` | Vitest (single run) |
| `npm run test:watch` | Vitest in watch mode |
| `npm run test:coverage` | Vitest with V8 coverage |

## Structure

```
src/
├── components/
│   ├── ui/            # Primitives (Button, Input, Card, Badge, Spinner)
│   ├── layout/        # AppLayout, OrgSwitcher
│   ├── customers/     # Feature components (CustomerFormDialog)
│   ├── ProtectedRoute.tsx
│   └── ErrorBoundary.tsx
├── pages/             # Route components, lazily loaded
├── hooks/             # useAuth, useCustomers, useDebounced
├── services/          # Axios client + API modules (auth, customers)
├── store/             # Zustand stores (auth, theme)
├── types/             # API contract types — mirror the Laravel Resources
├── lib/               # cn() and other helpers
└── test/              # Vitest setup
```

## How multi-tenancy works here

Every request carries two headers, applied centrally by the Axios interceptor in [`services/api.ts`](src/services/api.ts):

- `Authorization: Bearer <token>` — the Sanctum token
- `X-Organization: <slug>` — which organization to act in

Switching organizations (via `OrgSwitcher`) changes that header **and clears the React Query cache**, because the backing database changes — keeping cached rows would briefly show the previous organization's data.

> **Permissions are UI hints, not security.** `useAuthStore().can('customers.delete')` decides whether to *render* a control. The API authorizes every request independently; never treat the client check as the boundary.

## Conventions worth knowing

- **Types mirror the API.** When a Laravel API Resource changes, update `src/types/index.ts` in the same PR.
- **Query keys are organization-scoped** (`['customers', orgSlug, …]`) so cached data can never leak across organizations.
- **Forms set `noValidate`.** Native browser validation otherwise blocks submit before Zod runs, showing an unstyled native tooltip instead of our accessible, consistent error messages.
- **Validation is duplicated deliberately.** Zod schemas mirror the server's rules for fast feedback; the server stays authoritative.
- **Pages are lazy-loaded**, so each route ships as its own chunk. Recharts makes the dashboard chunk large (~107 KB gzipped) — it only loads for users who open that page.

## Testing

```bash
npm run test
```

Tests colocate with the code they cover (`auth.test.ts` beside `auth.ts`). API modules are mocked — these are unit/component tests, not integration tests against a live API.

## Dark mode

Driven by a `.dark` class on `<html>`, toggled by the `theme` Zustand store and persisted to localStorage. Colors are CSS custom properties defined in [`index.css`](src/index.css). Use the semantic tokens (`bg-background`, `text-muted-foreground`) rather than raw palette values so both themes stay correct.
