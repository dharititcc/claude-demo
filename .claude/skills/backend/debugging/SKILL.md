---
name: debugging
description: Structured debugging workflow for this Laravel app — reproduce, isolate, diagnose, and fix using logs, Telescope, tinker, and Pail. Use when something is broken, throwing, behaving unexpectedly, or differs between environments — especially the central-vs-tenant wrong-connection trap.
---

# Debugging

## Purpose

Turn "it's broken" into a reproducible root cause and a verified fix, methodically. This skill defines the tools and steps to diagnose issues without guesswork or shotgun changes.

## Scope

Runtime defects across the app: exceptions, wrong output, failed jobs, integration errors, and environment-specific bugs. Uses Laravel logs, Telescope (`app/Providers/TelescopeServiceProvider.php`, gated on `is_super_admin`), `tinker`, and `php artisan pail`. Performance issues route to `performance`; production incidents to the incident workflow.

## Responsibilities

- Reproduce the issue reliably before changing anything.
- Isolate the failing layer (HTTP, service, query, job, integration).
- Diagnose the root cause, not the symptom.
- Fix minimally and verify the behavior end-to-end.

## Best Practices

- **Reproduce first.** Find exact inputs/state that trigger it. If it only happens in prod, capture the request/context from Telescope.
- **Read the actual error.** Full stack trace, file, and line. Check `storage/logs/laravel.log`, `php artisan pail` (live), and Telescope (requests/queries/exceptions).
- **Suspect the wrong DB connection first.** The signature bug class here is a query running against the wrong database (central vs tenant). Symptoms: a silent cross-tenant read (data from another org, no error), or an error like `Table 'saas_central.permissions' doesn't exist` when a tenant-connection model runs on central. Confirm each model in the failing path pins its connection via `UsesCentralConnection`/`UsesTenantConnection`, and that the `tenant` middleware initialized the org DB (see `AppServiceProvider` and the connection traits). Generic "environment differences" will miss this.
- **Bisect the layers:** confirm which layer fails — is the controller reached? request validated? service called? query correct? job dispatched/processed? Add temporary targeted logging (`Log::debug`) or use `tinker` to exercise the service in isolation.
- **Inspect data/state:** use `tinker` to reproduce the query/relationship; check which connection a model resolves to (`$model->getConnectionName()`) when data looks wrong or missing.
- **Check environment differences:** config cache, `.env` values, Horizon workers running, Redis/DB connectivity, migration state (per tenant), timezone. "Works locally, not in prod" is usually config/cache/queue/permissions.
- **Change one thing at a time;** re-test after each change. Revert experiments that don't help.

## Coding Standards

- Remove all temporary `dd()`/`dump()`/debug logging before committing.
- Prefer a failing test that reproduces the bug over ad-hoc manual repro; keep it as a regression test.
- Fix the root cause; don't paper over with try/catch that swallows errors.

## Performance Guidelines

- For slow (not broken) behavior, switch to the `performance` skill and profile with Telescope rather than debugging blind.
- Watch for queue backlogs on the Horizon dashboard when jobs "don't run."

## Security Considerations

- Never expose stack traces to users in production (`APP_DEBUG=false`); read them from logs.
- Don't log secrets/PII while debugging; scrub before committing any added logging.
- Be cautious running `tinker` against production data — read-only unless intentionally fixing.

## Common Mistakes

- Changing code before reproducing the bug.
- Fixing the symptom (e.g., null check) without understanding why it's null.
- Leaving `dd()`/debug logs in committed code.
- Ignoring the stack trace and guessing.
- Forgetting stale config/route cache or a stopped Horizon worker as the real cause.
- Blaming "environment" when a model is querying the wrong DB connection (central vs tenant).
- Swallowing exceptions, hiding the real failure.

## Recommended Workflow

1. Reproduce with exact inputs; capture the full error from logs/Telescope.
2. Form a hypothesis about the failing layer; confirm by isolating it (`tinker`, targeted log).
3. Identify the root cause.
4. Apply the minimal fix; add a regression test.
5. Verify end-to-end (drive the real flow, not just the test); check no side effects.
6. Clear caches if config/routes changed; confirm in a prod-like state. Remove debug artifacts.

## Output Expectations

A stated root cause (not just a symptom), a minimal fix, a regression test where feasible, and confirmation the flow now works end-to-end. Evidence (log/trace excerpt, query) is cited, and any environment/config cause is called out. Files referenced as `path:line`.
