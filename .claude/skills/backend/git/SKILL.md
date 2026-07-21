---
name: git
description: Git and GitHub workflow standards for this project — single-`main` trunk, commit hygiene, pull requests, reviews, and merge/rebase practices. Use when branching, committing, opening PRs, or resolving conflicts. The standing rule: never branch, commit, or push unless the user explicitly asks.
---

# Git & GitHub Workflow

## Purpose

Keep history clean, changes reviewable, and the trunk deployable. This repo is a
monorepo (Laravel 13 API in `backend/`, React 19 SPA in `frontend/`) on a
**single `main` branch** — there is no Git Flow here. This skill defines how work
lands on `main` and the hygiene expected around it.

## Scope

Branching, commit messages, pull requests, code-review handoff, and conflict
resolution. Complements `commit` (how to craft a commit), `code-review` (what to
review), and `devops` (how it deploys).

## Standing rule

**Never branch, commit, or push unless the user explicitly asks.** Nothing has
been committed since the initial scaffold — the user drives every git write.
Making edits is not consent to commit them.

## Responsibilities

- Keep `main` deployable; land changes as small, focused commits.
- Write clear commit messages (see `commit`).
- Open reviewable PRs with context when the user asks; keep them scoped.
- Resolve conflicts safely without destroying others' work.

## Best Practices

- **Single trunk:** `main` is the default and only branch (`git branch -a` shows
  `main` and `origin/main`, nothing else). Work happens on `main`, or on a
  short-lived branch **only when the user asks for one**. There is no `master`,
  `develop`, or `feature/*`/`release/*`/`hotfix/*` scheme.
- **`develop` is a future intention, not reality:** the CI triggers
  (`.github/workflows/backend-ci.yml`, `frontend-ci.yml`) fire on
  `branches: [main, develop]`, so a `develop` branch is anticipated — but it does
  **not exist yet**. Don't teach or assume it as current state.
- **Short-lived branches when asked:** if the user wants a branch, use a clear
  name, branch off `main`, and merge back via PR. Delete it after merge.
- **Small, atomic commits:** one logical change each; imperative subject
  (`type(scope): summary`, see `commit`). Explain *why* in the body when
  non-obvious.
- **Rebase before merging** to keep history linear; never rebase shared history
  others have pulled.
- **PRs are focused and described:** intent, scope, testing done. Keep them small
  enough to review well.

## Coding Standards

- Commit messages: Conventional Commits, imperative mood (see `commit` for the
  full format and the required `Co-Authored-By` trailer).
- `.gitignore` excludes `.env`, `vendor/`, `node_modules/`, build output, and
  local artifacts — for both `backend/` and `frontend/`.
- Never commit secrets, `.env`, or generated files.
- One concern per commit/branch; don't mix unrelated changes.

## Performance Guidelines

- Keep PRs small — faster review, fewer conflicts.
- Rebase early to avoid large, painful merges.

## Security Considerations

- If a secret is ever committed, rotate it immediately — history removal alone is
  insufficient once pushed.
- Review diffs for accidentally staged `.env`/keys before committing.
- Protect `main` with required reviews and green CI (Pint, PHPStan, Pest run in
  `backend-ci.yml`).

## Common Mistakes

- Committing or pushing without the user asking.
- Assuming Git Flow / `master` / `develop` exist — they don't; it's single `main`.
- Committing `.env`/secrets or build artifacts.
- Giant, unfocused PRs that are hard to review.
- Force-pushing shared history, destroying teammates' commits.
- Merging with failing CI or unresolved conflicts.

## Recommended Workflow

1. Confirm the user actually wants a git write (branch/commit/push).
2. Work on `main` unless the user asks for a branch; if so, branch off `main`.
3. Make small, logical commits (see `commit`); run Pint + PHPStan + Pest locally.
4. If a PR is requested, push and open it with intent, scope, and testing notes.
5. Address review feedback; keep CI green.
6. Merge per the user's preference; delete any short-lived branch.

## Output Expectations

Changes land on `main` (or a user-requested short-lived branch) as atomic commits
with clear Conventional Commit messages, green CI, and no secrets/artifacts in
history. Conflicts are resolved without clobbering others' work. Only branch,
commit, or push when the user explicitly asks.
