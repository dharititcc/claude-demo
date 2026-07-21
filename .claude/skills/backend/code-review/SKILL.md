---
name: code-review
description: Code review checklist and standards for this Laravel codebase — correctness, architecture adherence, security, performance, and style. Use when reviewing a diff, PR, or your own change before committing.
---

# Code Review

## Purpose

Catch defects, security issues, and architectural drift before they merge, and keep the codebase consistent. This skill is the checklist a change must pass — whether reviewing a teammate's PR or self-reviewing before commit.

## Scope

Review of any application change: PHP (controllers/services/repositories/models), migrations, config, and tests. This is a headless JSON API — no Blade; output is shaped by API Resources. Complements `laravel`, `architecture`, `security`, `performance`, `database`, and `testing`, and pairs with the `/code-review` command.

## Responsibilities

- Verify correctness and that the change does what it claims.
- Enforce layered architecture and project conventions.
- Flag security, performance, and data-integrity risks.
- Keep scope minimal and style consistent.

## Best Practices

- **Read the intent first:** understand the ticket/goal, then check the diff against it. Reject scope creep — unrelated refactors belong in their own change.
- **Correctness:** trace edge cases, null/empty states, error paths, and concurrency. Does it handle failure of external calls?
- **Architecture:** controllers thin? logic in services? validation in Form Requests? new models pinned to the correct DB connection (`UsesCentralConnection`/`UsesTenantConnection`)?
- **Tenancy trap:** does any query run on the wrong connection (central vs tenant)? A central query hitting the tenant DB (or vice versa) is a silent cross-tenant read, not just a bug.
- **Security:** authorization present (`permission:`/policies)? input validated? cross-tenant `exists:` rules qualified with the central connection? no injection, no mass-assignment, no leaked secrets? `stripe/*` request-forgery exclusion left untouched (Stripe signature-verified)?
- **Performance:** any N+1 (a lazy load throws — `preventLazyLoading` is on), unbounded query, missing index, or sync work that should be queued?
- **Database:** migrations reversible and indexed? correct types (decimal for money)? casts set?
- **Tests:** meaningful coverage for happy path, authorization, and validation? green suite?
- **Style:** PSR-12 (Pint clean)? naming and structure match sibling files?

## Coding Standards

- Prefer specific, actionable feedback tied to `file:line` with a concrete suggestion.
- Rank findings by severity: correctness/security first, then performance, then style.
- Distinguish blocking issues from nits; don't block on preference.
- Confirm the change is minimal-scope and doesn't refactor unrelated code.

## Performance Guidelines

- Focus review effort on hot paths, list endpoints, and loops over collections.
- Verify eager loading, pagination, and query counts where relevant.

## Security Considerations

- Treat every new input, route, and file operation as a trust boundary to verify.
- Check that logs don't capture secrets or PII.
- Verify authorization is fail-closed and not duplicated inconsistently.

## Common Mistakes (to catch)

- Business logic in controllers instead of services.
- A query on the wrong DB connection (central vs tenant), or a new model missing its connection trait.
- N+1 / lazy loads (they throw under `preventLazyLoading`) and unpaginated lists.
- Missing authorization or validation; unqualified cross-tenant `exists:` rules.
- Non-reversible or unindexed migrations.
- Hardcoded role/permission strings instead of the `App\Enums` PHP enums.
- Secrets in code; broadened `stripe/*` request-forgery exclusion.

## Recommended Workflow

1. Read the goal and the full diff before commenting.
2. Walk the layered checklist: correctness → architecture → security → performance → database → tests → style.
3. Confirm the quality gates pass: `./vendor/bin/pint --dirty` (style), `./vendor/bin/phpstan analyse` (level 6), `./vendor/bin/pest` (real MySQL), and `php artisan l5-swagger:generate` (regenerate API docs). PHPStan and swagger regeneration are required gates, not optional.
4. Leave ranked, actionable comments with `file:line` and suggested fixes.
5. Approve only when blocking issues are resolved and scope is clean.

## Output Expectations

A prioritized list of findings (severity-ordered), each with `file:line` and a concrete fix, plus an explicit approve/request-changes verdict. False positives are avoided by verifying claims against the code; uncertainty is stated, not asserted.
