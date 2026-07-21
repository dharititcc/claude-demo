---
name: docker
description: Docker standards for this project's DEV stack — the root docker-compose.yml (php-fpm, nginx, mysql, redis, saas_horizon, frontend, phpMyAdmin) and docker/ Dockerfiles. Use when changing compose services, the PHP/Node images, or debugging container build/runtime. Prod hardening is aspirational, not built.
---

# Docker & Containers

## Purpose

Describe and maintain this project's **development** container stack accurately.
Docker here is a dev convenience (Laragon is the other local option on Windows) —
**not** a hardened production image. The root `backend` skill's tenancy rules
override anything here; any container that migrates must run **both** migration
commands (below).

## Scope

The root `docker-compose.yml` and the `docker/` build context: `docker/php/Dockerfile`,
`docker/node/Dockerfile`, `docker/nginx/default.conf`, `docker/mysql/my.cnf`,
`docker/php/php.ini`. Host OS tuning is in `linux-server`; release flow in `devops`.

## Responsibilities

- Keep the dev compose stack working: php-fpm, nginx, mysql, redis, Horizon, the frontend node dev server, and phpMyAdmin.
- Keep the images buildable and layer-cache-friendly.
- Describe the setup honestly — do not claim prod hardening the repo doesn't have.

## Best Practices

- **The actual dev stack (`docker-compose.yml`).** Services:
  - `backend` (`saas_backend`) — php-fpm from `docker/php/Dockerfile`, bind-mounts `./backend:/var/www/html`, exposes 9000 internally.
  - `nginx` (`saas_nginx`) — `nginx:1.27-alpine`, publishes **8000:80**, config from `docker/nginx/default.conf`.
  - `horizon` (`saas_horizon`) — same php image, `command: php artisan horizon`. This is how the queue runs; there is **no separate scheduler service**.
  - `frontend` (`saas_frontend`) — `docker/node/Dockerfile` (`node:20-alpine`), `npm run dev -- --host`, publishes **5173**.
  - `mysql` (`saas_mysql`) — `mysql:8.0`, publishes **3306**, healthcheck + `docker/mysql/my.cnf`.
  - `redis` (`saas_redis`) — `redis:7-alpine`, appendonly, publishes **6379**. Redis is required (cache tagging + Horizon).
  - `phpmyadmin` (`saas_phpmyadmin`) — publishes **8080**.
- **The PHP image is single-stage and runs as ROOT.** `docker/php/Dockerfile:2` is
  `php:8.3-fpm-alpine`, installs `pdo_mysql mbstring bcmath intl zip gd pcntl opcache` + the
  redis pecl ext, then `composer install`. There is **no multi-stage build and no `USER` directive**
  — do not describe it as slim/non-root; it isn't.
- **Code is bind-mounted, not baked.** `./backend` and `./frontend` are volumes, so the image's
  `composer install` is a dev convenience — the live code is the host's. This is a dev pattern.
- **Layer caching is used:** both Dockerfiles copy dependency manifests and install before copying
  source (`docker/php/Dockerfile:23`, `docker/node/Dockerfile:7`).
- **Persistent state in named volumes:** `mysql_data`, `redis_data`.
- **Env for the frontend** is set inline (`VITE_API_URL=http://localhost:8000`); backend reads its `.env` from the bind-mounted `./backend`.

## Coding Standards

- Order Dockerfile layers for cache efficiency (manifests → install → source) — already done; keep it.
- One responsibility per compose service block; keep container names (`saas_*`) and the `saas` bridge network consistent.
- Match the image PHP/Node versions (8.3 / 20) to what CI and prod use.

## Performance Guidelines

- Alpine base images keep pulls small; keep them.
- Dependencies install before source copy so app edits don't rebuild deps.
- Horizon runs in its own container — scale/adjust it independently of php-fpm.
- OPcache is compiled into the php image; php.ini overrides mount from `docker/php/php.ini`.

## Security Considerations

- **This is a dev stack.** mysql (3306), redis (6379), and phpMyAdmin (8080) are **published to the
  host** — fine for local dev, **not** for production. Don't present the current setup as secure-by-default.
- The php container runs as **root**; there is **no `.dockerignore`** (build context includes everything).
- Secrets: the backend `.env` lives in the bind-mounted `./backend` (dev). Do not commit it.
- **Prod hardening is future/not-yet-done** — if you recommend multi-stage builds, a non-root `USER`,
  a `.dockerignore`, internal-only data ports, or a dedicated scheduler container, frame it clearly as
  a proposed change versus the current dev compose, not as existing behavior.

## Common Mistakes

- Claiming multi-stage/non-root/`.dockerignore`/internal-only ports/a scheduler service — **none exist** here.
- Adding a "queue worker" service — the queue is the `horizon` service (`php artisan horizon`).
- A migration step that runs `migrate` but not `tenants:migrate` — database-per-tenant needs **both** (see below).
- Baking secrets into image layers, or assuming the image ships the app code (it's bind-mounted in dev).
- Using `latest` tags — the compose files pin versions; keep them pinned.

## Recommended Workflow

1. `docker compose up -d --build`; confirm mysql healthcheck passes before the app connects.
2. For a fresh DB, exec into `saas_backend` and run **both** `php artisan migrate` **and**
   `php artisan tenants:migrate` (central + every tenant DB) — never just one.
3. Verify Horizon is processing (`saas_horizon` logs) and Redis/DB connectivity.
4. Reach the API via nginx on **:8000**, the SPA on **:5173**, phpMyAdmin on **:8080**.
5. When changing a service, edit the one compose block / Dockerfile; keep versions pinned and layers cache-friendly.
6. If proposing prod hardening, write it as a separate future image — don't mutate the dev stack's assumptions silently.

## Output Expectations

Changes match the **actual dev compose** (php-fpm, nginx:8000, mysql:3306, redis:6379,
saas_horizon, frontend:5173, phpMyAdmin:8080) and the single-stage root php image with
bind-mounted code. Any migration step runs **both** `migrate` and `tenants:migrate`.
Prod hardening is labeled as not-yet-done. Files referenced as `path:line`.
