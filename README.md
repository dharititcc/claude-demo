# SaaS Platform — Multi-Tenant SaaS Starter

A production-grade, enterprise multi-tenant SaaS platform built with **Laravel 12** (REST API) and **React 19 + TypeScript**. Tenant isolation uses a **database-per-tenant** model (`stancl/tenancy`).

> **Status:** Active incremental build. This repository is delivered in phases — each phase is runnable and tested before the next begins. See [docs/ROADMAP.md](docs/ROADMAP.md) for the current phase and what is complete vs. planned.

---

## Table of Contents

- [Project Overview](#project-overview)
- [Architecture](#architecture)
- [Features](#features)
- [Screenshots](#screenshots)
- [Tech Stack](#tech-stack)
- [Installation](#installation)
- [Docker Setup](#docker-setup)
- [Local Development](#local-development)
- [API Documentation](#api-documentation)
- [Folder Structure](#folder-structure)
- [Testing](#testing)
- [Deployment](#deployment)
- [License](#license)

---

## Project Overview

This platform lets multiple **organizations (tenants)** sign up and operate in complete data isolation. Each tenant gets its own database, provisioned automatically on organization creation. A central database holds the tenant registry, users, plans, and billing.

Core capabilities: authentication (incl. 2FA and social login), team & role management (RBAC), Stripe subscription billing, and business modules (Customers, Projects, Tasks, Calendar, Files) — all exposed through a versioned REST API and consumed by a React SPA.

## Architecture

```
                    ┌─────────────────────────────┐
                    │   React 19 SPA (frontend/)   │
                    │   Vite · TS · TanStack Query │
                    └──────────────┬──────────────┘
                                   │ HTTPS / REST (Sanctum token)
                    ┌──────────────▼──────────────┐
                    │   Nginx  →  Laravel 12 API   │
                    │        (backend/)            │
                    └──────┬───────────────┬───────┘
                           │               │
              ┌────────────▼───┐   ┌───────▼──────────┐
              │  Central DB    │   │  Redis           │
              │  (tenants,     │   │  cache · queue · │
              │   users, plans)│   │  Horizon · session│
              └────────┬───────┘   └──────────────────┘
                       │ provisions
        ┌──────────────┼───────────────┐
   ┌────▼────┐   ┌─────▼────┐    ┌──────▼───┐
   │ tenant_1│   │ tenant_2 │ …  │ tenant_N │   (database-per-tenant)
   └─────────┘   └──────────┘    └──────────┘
```

**Backend layering** (clean architecture): `Controllers → Form Requests → Services → Repositories → Models`. Cross-cutting concerns (tenancy, RBAC, audit logging) live in middleware, global scopes, and observers.

## Features

- **Multi-tenancy** — database-per-tenant isolation, automatic provisioning, tenant-aware queues & cache.
- **Authentication** — login, registration, email verification, password reset, change password, session management, TOTP 2FA, Google & GitHub social login.
- **Organizations & Teams** — multiple orgs per user, teams, member invitations, roles & permissions (Spatie).
- **RBAC** — Super Admin, Tenant Owner, Admin, Manager, Employee, Viewer.
- **Billing** — Stripe monthly/annual plans, trials, coupons, invoices, taxes, usage limits.
- **Modules** — Customers, Projects, Tasks (Kanban), Calendar, File Manager, Notifications, Audit Logs.
- **Platform** — versioned REST API, pagination/filter/sort/search, rate limiting, OpenAPI/Swagger docs, Horizon queues, Telescope, Redis cache.

## Screenshots

_Placeholders — add real screenshots to `docs/screenshots/` as the UI lands._

| Dashboard | Customers | Billing |
|-----------|-----------|---------|
| ![Dashboard](docs/screenshots/dashboard.png) | ![Customers](docs/screenshots/customers.png) | ![Billing](docs/screenshots/billing.png) |

## Tech Stack

**Backend:** Laravel 12 · PHP 8.3 · MySQL 8 · Redis · Horizon · Telescope · Sanctum · Spatie Permission · Pest · PHPStan (Larastan) · Pint · L5-Swagger · Docker
**Frontend:** React 19 · TypeScript · Vite · React Router · TanStack Query · Axios · TailwindCSS · shadcn/ui · React Hook Form · Zod · Zustand · Recharts · TanStack Table · React Hot Toast · Vitest

## Installation

### Prerequisites

- Docker & Docker Compose **or** local PHP 8.3+, Composer, Node 20+, MySQL 8, Redis.

### Quick start (Docker)

```bash
git clone <your-repo-url> saas-platform
cd saas-platform
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env
docker compose up -d
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate --seed
```

App: `http://localhost:5173` · API: `http://localhost:8000` · Swagger: `http://localhost:8000/api/documentation`

## Docker Setup

Services defined in [`docker-compose.yml`](docker-compose.yml): `backend` (PHP-FPM), `frontend` (Vite/Node), `nginx`, `mysql`, `redis`, `phpmyadmin`. See [docker/README.md](docker/README.md) for per-service config.

## Local Development

```bash
# Backend
cd backend
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve            # http://localhost:8000
php artisan horizon          # queue workers (separate terminal)

# Frontend
cd frontend
npm install
cp .env.example .env
npm run dev                  # http://localhost:5173
```

## API Documentation

OpenAPI/Swagger generated via L5-Swagger. After booting the backend:

```bash
php artisan l5-swagger:generate
```

Browse at `/api/documentation`. A Postman collection lives in [`postman/`](postman/).

## Folder Structure

```
saas-platform/
├── backend/          # Laravel 12 REST API
├── frontend/         # React 19 + TypeScript SPA
├── docker/           # Dockerfiles & service configs
├── docs/             # Architecture, roadmap, ADRs
├── postman/          # Postman collection & environments
├── database/         # Shared SQL / seed assets
├── .github/          # CI workflows
├── docker-compose.yml
└── README.md
```

## Testing

```bash
# Backend
cd backend && ./vendor/bin/pest --coverage

# Frontend
cd frontend && npm run test
```

Target: **80%+ coverage**. CI enforces Pint, PHPStan, Pest, Vitest, and a React build on every push (see [`.github/workflows`](.github/workflows)).

## Deployment

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). In short: build images, run migrations on the central DB, provision tenant DBs on org creation, serve the API behind Nginx and the SPA from a CDN/static host.

## License

[MIT](LICENSE) © itcc
