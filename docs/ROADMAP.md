# Delivery Roadmap

This project is built **incrementally**. Every phase must be runnable, migrated, and green in CI before the next begins. This file is the source of truth for what is done vs. planned.

Legend: ✅ done · 🚧 in progress · ⬜ planned

---

## Phase 1 — Foundation
- ✅ Monorepo structure & git
- ✅ Governance files (README, LICENSE, CONTRIBUTING, SECURITY, CoC, CHANGELOG, editorconfig, gitignore)
- 🚧 Laravel 12 backend bootstrap
- ⬜ React 19 + TS frontend bootstrap
- ⬜ Docker Compose (backend, frontend, nginx, mysql, redis, phpmyadmin)
- ⬜ CI workflows (Pint, PHPStan, Pest, Vitest, build)

## Phase 2 — Tenancy + Auth + RBAC
- ⬜ `stancl/tenancy` — central + per-tenant DBs, automatic provisioning
- ⬜ Sanctum authentication (login, register, verify, reset, change password, sessions)
- ⬜ Spatie Permission roles: Super Admin, Tenant Owner, Admin, Manager, Employee, Viewer
- ⬜ Security: login history, failed-login lockout, password policy, audit log foundation
- ⬜ 2FA (TOTP) + social login (Google, GitHub)

## Phase 3 — Vertical Slice (Organizations → Dashboard → Customers)
- ⬜ Organizations CRUD + settings (logo, slug, timezone, currency, language, status)
- ⬜ Teams, members, invitations
- ⬜ Dashboard API + charts (revenue, active users, storage, API usage)
- ⬜ Customers module: CRUD, search, filter, sort, import/export, tags, notes, attachments
- ⬜ React: auth pages, layout/dark mode, protected routes, dashboard, customers UI
- ⬜ Pest feature/unit tests + Vitest for the slice

## Phase 4 — Billing
- ⬜ Stripe integration (Cashier): monthly/annual plans, trials, coupons, invoices, taxes, usage limits

## Phase 5 — Remaining Modules
- ⬜ Projects (status, tasks, files, comments, timeline, members)
- ⬜ Tasks (Kanban, calendar, priority, labels, time tracking, subtasks, comments)
- ⬜ Calendar (meetings, reminders, events, recurring)
- ⬜ File Manager (upload, folders, preview, versioning, share links, quota)
- ⬜ Notifications (email, database, Slack, webhooks, push)
- ⬜ Audit Logs (full coverage)

## Phase 6 — Hardening
- ⬜ OpenAPI/Swagger complete
- ⬜ Performance: Redis cache, Horizon, eager loading, cursor pagination, indexes
- ⬜ Coverage ≥ 80% both apps
- ⬜ Deployment docs & production checklist
