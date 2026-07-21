---
name: telescope
description: Laravel Telescope usage standards for this app — local/staging debugging of requests, queries, jobs, mail, exceptions, and cache, plus production safety (auth gating, pruning). Use when profiling, debugging, or configuring Telescope.
---

# Laravel Telescope

## Purpose

Use Telescope as the local/staging window into the application's runtime — requests, queries, jobs, mail, events, cache, and exceptions — to debug and profile effectively, while keeping it safe if enabled beyond local. See `config/telescope.php` and `TelescopeServiceProvider`.

## Scope

Telescope configuration and usage for debugging/profiling: watchers, data retention (pruning), access gating, and environment enablement. Complements `debugging` and `performance`; production-grade metrics/alerting are in `pulse` and `sentry`.

## Responsibilities

- Enable rich local visibility into the request lifecycle.
- Use Telescope data to find N+1s, slow queries, failed jobs, and misfired mail/events.
- Keep Telescope locked down and pruned wherever it's enabled.

## Best Practices

- **Primarily local/staging:** enable via env (e.g., `TELESCOPE_ENABLED`); keep it off or tightly gated in production to avoid overhead and data exposure.
- **Use the right watcher:** Requests (timing, payload), Queries (count, duration, N+1), Jobs (success/failure/retries), Mail (rendered previews), Exceptions, Cache, Events, and Redis. Filter by tag/batch to trace one request end-to-end.
- **Hunt N+1s:** open a slow request, inspect the Queries tab for repeated similar queries — fix with eager loading (see `performance`).
- **Debug jobs/mail:** confirm dispatch, inspect payloads, and read failure exceptions without hitting real recipients.
- **Prune aggressively:** schedule `telescope:prune` (e.g., keep 24–72h) so the telescope tables don't bloat the DB.
- **Tag deliberately:** add tags to correlate related entries when chasing a specific flow.

## Coding Standards

- Configuration in `config/telescope.php`; access control in `TelescopeServiceProvider::gate()`.
- Don't leave Telescope-specific debug code in application logic; it's an observability tool, not app behavior.
- Keep the migration set (`telescope_entries`) intact; don't hand-edit its tables.

## Performance Guidelines

- Telescope adds overhead — disable in production or restrict to admins and short retention.
- Prune on a schedule; large entry tables slow the dashboard and the DB.
- Disable noisy watchers you don't need to reduce write volume.

## Security Considerations

- **Gate access** in `TelescopeServiceProvider::gate()` to authorized admins only — Telescope exposes requests, payloads, and potentially PII/secrets.
- Never expose Telescope publicly in production.
- Be aware Telescope may record sensitive request data; restrict retention and access accordingly, and avoid logging secrets through it.

## Common Mistakes

- Leaving Telescope enabled and ungated in production → data leak + overhead.
- No pruning → runaway `telescope_entries` table.
- Ignoring the Queries tab when debugging slowness.
- Treating Telescope data as authoritative production monitoring (use Sentry/Pulse for that).
- Committing environment-specific Telescope toggles that enable it everywhere.

## Recommended Workflow

1. Enable Telescope locally; reproduce the request/job under investigation.
2. Open the entry; review Requests → Queries → Jobs/Mail/Exceptions as relevant.
3. Diagnose (N+1, slow query, failed job, misfired mail) and fix per `performance`/`debugging`.
4. Verify the fix reduces query count/time in Telescope.
5. Ensure production gating and scheduled pruning are configured.

## Output Expectations

Telescope is used to produce concrete evidence (query counts, timings, job/mail state) that drives a fix, and is kept gated and pruned wherever enabled. Findings cite the Telescope evidence and the fixed files as `path:line`. Configuration stays in `config/telescope.php`/`TelescopeServiceProvider`.
