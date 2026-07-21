---
name: performance
description: Performance engineering for this Laravel app — query optimization, N+1 elimination, caching (Redis), eager loading, queueing slow work, denormalized tenant stats, and profiling with Telescope. Use when a page/endpoint is slow, when reviewing for efficiency, or before shipping data-heavy features.
---

# Performance

## Purpose

Keep the application fast and scalable under real load by making performance a design concern, not an afterthought. This skill defines how to find, fix, and prevent performance problems across the request cycle and background work.

## Scope

Application-level performance: database queries, Eloquent usage, caching (Redis), HTTP response time, memory, and async offloading. Profiling via Laravel Telescope (the only profiler installed — no Pulse, no Debugbar). Infrastructure tuning (PHP-FPM, Nginx, OPcache) is covered in `linux-server`/`devops`.

## Responsibilities

- Eliminate N+1 and unbounded queries.
- Cache expensive, stable computations and invalidate correctly.
- Move slow/external work off the request path into queues.
- Measure before and after every optimization — no guessing.

## Best Practices

- **Measure first.** Use Telescope (queries, requests, jobs) locally. Optimize the actual hotspot, not the assumed one.
- **Eager load** relationships with `with()`/`load()`; use `loadMissing` to avoid double loading. Add `withCount` instead of loading children to count them. `preventLazyLoading` is on, so an unloaded relationship throws in dev/test — treat every violation as a bug to fix, not a warning.
- **Select narrow columns** for lists/exports; avoid `SELECT *` on wide tables.
- **Chunk/stream** large datasets: `chunkById()`, `lazy()`, `cursor()`. Never `->get()` an unbounded table into memory.
- **Cache** with `Cache::remember(key, ttl, fn)` for expensive, read-heavy, slowly-changing data on the **Redis** store. The cache store is **taggable** and tenancy scopes entries per tenant, so keys don't collide across orgs. Invalidate on write. Cache framework config/routes/events in production (`config:cache`, `route:cache`, `event:cache`).
- **Queue slow work:** mail, Stripe/Cashier sync, inbound webhook processing, tenant stats refresh, bulk updates. Keep the request cycle lean (see `queues`).
- **Aggregate in SQL** (`count`, `sum`, `avg`, `groupBy`) rather than in PHP collections.
- **Denormalize cross-tenant rollups.** Platform-wide dashboards read the central `organization_stats` table, refreshed hourly by queued per-tenant `RefreshTenantStats` jobs (`app:refresh-org-stats`) — never fan out a live query across every tenant DB on request.
- **Paginate** every list endpoint; never serialize thousands of rows into a JSON response.

## Coding Standards

- No lazy loads — resolve data in the controller/service with eager loads (`preventLazyLoading` throws otherwise).
- Cache keys are explicit, namespaced, and versioned; document TTLs.
- Prefer Collection methods for in-memory transforms, but only on already-bounded data.
- Wrap batched writes in a single transaction; avoid per-row round trips.

## Performance Guidelines

- Target: list endpoints issue a small, constant number of queries regardless of row count.
- Index every filtered/sorted/joined column (see `database`).
- Debounce and batch external API calls; respect rate limits with retries/backoff.
- Use OPcache in production; cache framework config/routes/views on deploy.
- Watch memory on reports/exports — stream to disk rather than building giant arrays.

## Security Considerations

- Don't cache per-user or sensitive data under shared keys; scope cache keys by user/tenant where relevant.
- Rate-limit expensive public endpoints to prevent abuse/DoS.
- Ensure cache invalidation can't leak stale authorization state (e.g., cached permission checks).

## Common Mistakes

- N+1 queries from unloaded relationships in loops.
- Over-fetching then filtering in PHP instead of SQL.
- Caching without an invalidation strategy → stale data bugs.
- Synchronous mail/PDF/API calls blocking the request.
- Unpaginated lists and unbounded `->get()`.
- Optimizing without measuring, or micro-optimizing cold paths.

## Recommended Workflow

1. Reproduce and measure the slow path with Telescope; record query count and timing.
2. Identify the dominant cost: queries, external calls, CPU, or memory.
3. Apply the smallest effective fix — eager load, index, cache, paginate, or queue.
4. Re-measure; confirm query count/time dropped and behavior is unchanged.
5. Add a regression guard (a test asserting query count) where feasible.

## Output Expectations

A measured improvement (before/after query count and timing), no behavior change, correct cache invalidation, and slow work moved to queues. The change references the profiling evidence and concrete files as `path:line`.
