---
name: documentation
description: Technical documentation standards for this project — README, docs/ guides, the OpenAPI/Swagger API spec, the Postman collection, code comments, ADRs, and runbooks. Use when documenting a feature, integration, decision, or process, or updating existing docs.
---

# Documentation

## Purpose

Make the system understandable and operable by others (and future you). This
skill defines what to document, where it lives, and the quality bar so docs stay
accurate and useful across the monorepo (Laravel 13 API in `backend/`, React 19
SPA in `frontend/`).

## Scope

Project docs under `docs/`, the root `README.md`, the Postman collection, the
generated OpenAPI/Swagger spec (l5-swagger), code-level comments, architecture
decision records (ADRs), and operational runbooks.

## Responsibilities

- Keep docs accurate and in sync with code — update them in the same change.
- Document the *why* (decisions, constraints), not just the *what*.
- Provide runnable, verified commands and examples.
- Keep the API docs (OpenAPI attributes) current as endpoints change.

## Where the real docs live

- **`docs/GUIDE.md`** — the whole-project orientation entry point (setup,
  architecture, demo credentials). Start here to understand the system.
- **`docs/DEPLOYMENT.md`** — deployment procedure and environment notes.
- **`docs/ROADMAP.md`** — planned work and phasing.
- **`docs/presentation.html`** — project presentation/overview.
- **`README.md`** (root) — quick setup/run for the monorepo.
- **`postman/SaaS-Platform.postman_collection.json`** — importable API request
  collection for exercising the endpoints.
- **OpenAPI/Swagger spec (l5-swagger)** — the API-doc deliverable, generated from
  `#[OA\...]` attributes on the controllers. It is a first-class deliverable, not
  an afterthought.

## Best Practices

- **Right doc for the audience:** `README` = setup/run; `docs/GUIDE.md` = whole
  project orientation; `docs/*` = deep topic guides; ADRs = decisions with
  trade-offs; runbooks = step-by-step ops.
- **Update docs with the code.** A behavior change that leaves docs stale is an
  incomplete change.
- **API docs are code:** endpoints carry `#[OA\...]` attributes (see e.g.
  `backend/app/Http/Controllers/Api/V1/...`). When you add/change an endpoint,
  update its attributes and regenerate the spec with
  `php artisan l5-swagger:generate`. Keep the Postman collection in step if the
  contract changes.
- **Show, don't just tell:** include exact commands, file paths, and short code
  samples. Verify commands actually run.
- **Explain decisions:** for non-obvious choices, record why and the alternatives
  (ADR). Convert relative dates to absolute.
- **Comment intent, not mechanics:** code says *what*; comments say *why*.
  Document tricky invariants (e.g. tenant vs central DB connection, the
  `stripe/*` request-forgery exclusion) and gotchas.
- **Keep it discoverable:** link related docs from `docs/GUIDE.md`; avoid
  duplicating content across files — link instead.

## Coding Standards

- Markdown: clear headings, fenced code blocks with language, tables for
  structured data.
- Concise and scannable; short paragraphs, bullet lists, no filler.
- Consistent terminology matching the codebase (tenant/central connection,
  Service, Policy, Resource).
- File paths as clickable references where the tool supports it.

## Performance Guidelines

- Prefer one authoritative doc per topic, linked from `docs/GUIDE.md`, over
  scattered duplicates (reduces drift/maintenance).
- Let the OpenAPI spec be the single source of truth for endpoint shapes rather
  than hand-maintaining a parallel API reference.

## Security Considerations

- Never put real secrets, tokens, internal URLs, or PII in docs.
- Use placeholders (`YOUR_API_KEY`) in examples; the demo credentials in
  `docs/GUIDE.md` are for the throwaway demo tenant only.
- Don't document exploit details beyond what's needed to fix/operate.

## Common Mistakes

- Docs that drift out of sync with code.
- Changing an endpoint without updating its `#[OA\...]` attributes / regenerating
  the spec.
- Commenting the obvious while omitting the tricky *why*.
- Unverified commands/examples that don't run.
- Duplicated content across multiple files that diverge.
- Secrets or environment-specific values pasted into docs.

## Recommended Workflow

1. Identify the audience and the right doc/location (usually `docs/GUIDE.md` or a
   topic guide under `docs/`).
2. Write concisely: purpose, steps/commands, examples, and the *why*.
3. Verify every command/example runs; use placeholders for secrets.
4. For API changes, update `#[OA\...]` attributes, run
   `php artisan l5-swagger:generate`, and update the Postman collection.
5. Cross-link related docs from `docs/GUIDE.md`; update docs in the same change as
   the code.

## Output Expectations

Accurate, concise, verified documentation in the correct location, updated
alongside the code it describes, with decisions explained and no secrets. The
OpenAPI spec and Postman collection stay in step with the API; cross-links from
`docs/GUIDE.md` are maintained.
