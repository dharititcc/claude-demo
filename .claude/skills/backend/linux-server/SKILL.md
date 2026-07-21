---
name: linux-server
description: Linux production server standards for this Laravel app — Nginx config, PHP-FPM, permissions, HTTPS/TLS, Supervisor running Horizon, cron scheduler, OPcache, and hardening. Use when configuring, deploying to, or troubleshooting the production Linux server.
---

# Linux Server (Production)

## Purpose

Run the Laravel app reliably and securely on a Linux server. This skill covers web server, PHP-FPM, process supervision, TLS, permissions, and hardening so production is fast, stable, and safe.

## Scope

Production/staging Linux host configuration: Nginx, PHP 8.3-FPM, file permissions, HTTPS/TLS, Supervisor (running Horizon), cron (scheduler), OPcache, logs, and OS hardening. Containerized setups are in `docker`; release flow in `devops`.

## Responsibilities

- Serve the app via a correctly configured web server + PHP-FPM.
- Keep queue workers supervised and the scheduler cron installed.
- Enforce HTTPS, correct permissions, and OS hardening.
- Ensure logs, backups, and monitoring are in place.

## Best Practices

- **Web root:** point Nginx at `public/`, never the project root. Route all requests to `index.php`. Deny direct access to `.env`, `storage`, `vendor`, and dotfiles.
- **Nginx + PHP-FPM:** `try_files $uri /index.php?$query_string`; pass PHP to the FPM socket; set sensible `client_max_body_size` for uploads, gzip, and long cache headers for hashed static assets. The project standardizes on Nginx.
- **PHP-FPM tuning:** size the pool (`pm`, `pm.max_children`) to RAM; set `memory_limit`, `upload_max_filesize`, `post_max_size`, and `max_execution_time` appropriately. Enable **OPcache** in production.
- **Queue workers via Supervisor** (or systemd): run **`php artisan horizon`** with autostart/autorestart and a log file. Horizon manages its own pool of worker processes — do **not** set `numprocs` > 1 for it, and never run raw `php artisan queue:work` alongside it. `php artisan horizon:terminate` on deploy so new code loads.
- **Scheduler cron:** a single entry — `* * * * * php /path/artisan schedule:run >> /dev/null 2>&1` — drives all scheduled tasks, including the hourly `app:refresh-org-stats` that rebuilds the denormalized central `organization_stats` table.
- **Permissions:** app owned by the deploy user; web server group needs write only to `storage/` and `bootstrap/cache`. Never `chmod -R 777`.
- **TLS/HTTPS:** valid certificate (e.g., Let's Encrypt/certbot with auto-renew); redirect HTTP→HTTPS (works with the app's `RedirectToHttps`); modern ciphers, HSTS.
- **Production caches on deploy:** `config:cache`, `route:cache`, `view:cache`, `event:cache`.

## Coding Standards / Config Hygiene

- Keep server config in version control / provisioning (idempotent), not hand-edited and forgotten.
- Match PHP 8.3 to the app requirement; keep extensions (pdo_mysql, redis, mbstring, etc.) installed.
- Document any manual server step in the runbook (see `documentation`).

## Performance Guidelines

- OPcache on; tune FPM pool to workload and RAM.
- Serve static assets directly via the web server with far-future cache headers (hashed filenames).
- Use Redis for cache/queue/session where enabled; keep MySQL tuned and on fast storage.
- Scale Horizon's worker pool to queue depth (monitor via the Horizon dashboard).

## Security Considerations

- **Firewall:** expose only 80/443 (and restricted SSH); keep MySQL/Redis bound to localhost/private network.
- **SSH hardening:** key-only auth, no root login, non-standard port optional, fail2ban.
- **Least privilege:** dedicated deploy and DB users; no running the app as root.
- **Keep patched:** OS and PHP security updates; `composer audit` in CI.
- Protect `.env` (not web-accessible, restrictive perms); `APP_DEBUG=false` in production.
- Regular backups (DB + `storage/`) with tested restores; monitor via Horizon (queues) and Telescope (requests/exceptions).

## Common Mistakes

- Web root at project root instead of `public/` → source/`.env` exposure.
- `chmod -R 777` instead of scoped `storage`/`bootstrap/cache` write access.
- Queue workers run manually (not supervised) → die and stop processing.
- Missing/duplicated scheduler cron.
- No HTTPS or no auto-renew → expired certs.
- OPcache/config cache not enabled → slow production.
- MySQL/Redis exposed publicly; SSH password auth left on.

## Recommended Workflow

1. Configure the web server to serve `public/` via PHP-FPM 8.3; deny sensitive paths.
2. Tune PHP-FPM/OPcache; set upload/memory limits.
3. Set up Supervisor to run `php artisan horizon` and the single `schedule:run` cron.
4. Install TLS with auto-renew; enforce HTTPS.
5. Set scoped permissions; harden SSH/firewall; bind DB/Redis privately.
6. On deploy: cache config/routes/views, `queue:restart`, verify workers/scheduler, and smoke-test; confirm backups and monitoring.

## Output Expectations

A server serving `public/` over HTTPS with tuned PHP-FPM/OPcache, supervised queue workers, an installed scheduler cron, scoped permissions, a firewall exposing only necessary ports, `APP_DEBUG=false`, and working backups/monitoring. Manual steps are documented in the runbook; config is reproducible.
