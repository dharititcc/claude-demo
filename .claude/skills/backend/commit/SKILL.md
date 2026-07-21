---
name: commit
description: Create clean, well-structured git commits for this project — review changes, stage deliberately, and write clear Conventional Commit messages on the single `main` trunk. Use when the user asks to commit, stage, or save changes to git. Only commit when explicitly asked.
---

# Code Commit

## Purpose

Turn a set of working-tree changes into one or more clean, atomic, well-described
commits that keep history reviewable. This skill defines exactly how to inspect,
stage, message, and record commits in this monorepo (Laravel 13 API in
`backend/`, React 19 SPA in `frontend/`).

## Scope

The local commit workflow: reviewing the diff, staging the right files, writing
the message, and running `git commit`. Trunk/PR/push conventions are in `git`;
what to review for quality/security before committing is in `code-review`. This
skill does **not** push or open PRs unless explicitly asked.

## Responsibilities

- Inspect what actually changed before committing anything.
- Group changes into atomic, single-purpose commits.
- Write clear Conventional Commit messages explaining the *why*.
- Keep secrets/junk out; end every message with the required trailer.

## Golden rules

- **Only commit when the user asks.** Never auto-commit after making edits.
  Nothing has been committed since the initial scaffold — the user drives every
  commit.
- **Single `main` trunk.** There is no `master`/`develop`/`feature/*` scheme here
  (see `git`) — commits normally land on `main`. Don't switch or create branches
  unless the user asks.
- **Never** use `git rebase -i`, `git add -i`, or `git commit --amend` on
  already-pushed commits; prefer new commits.
- **Never** skip hooks (`--no-verify`) or bypass signing unless the user
  explicitly asks. If a hook fails, fix the underlying issue.
- **Never** commit `.env`, secrets, `vendor/`, `node_modules/`, or build
  artifacts.

## Best Practices

- **Review first:** run `git status`, `git diff` (staged + unstaged), and
  `git log --oneline -5` to match the repo's style. Understand every change.
- **Atomic commits:** one logical change per commit. Split unrelated changes with
  `git add <path>` (specific files/hunks), not a blanket `git add -A`.
- **Conventional Commits format:**
  ```
  <type>(<optional scope>): <imperative subject ≤ ~72 chars>

  <body: what & why, wrapped ~72 cols, when non-obvious>

  <optional footer: BREAKING CHANGE: ...>
  ```
  Types: `feat`, `fix`, `refactor`, `perf`, `test`, `docs`, `chore`, `style`,
  `build`, `ci`. Scope is a project domain: `auth`, `orgs`/`tenancy`,
  `customers`, `projects`, `tasks`, `calendar`/`events`, `files`,
  `team`/`invitations`, `billing`, `notifications`, `audit`, `admin`.
- **Imperative mood:** "Add annual plan swap", not "Added"/"Adds".
- **Explain the why:** the diff shows *what*; the body captures *why* and
  trade-offs, especially for tenancy/billing/security-sensitive changes.
- **Verify before committing:** run `./vendor/bin/pint --dirty` (style),
  `./vendor/bin/phpstan analyse` (level 6), and `./vendor/bin/pest` when the
  change is non-trivial. If you touched API annotations, run
  `php artisan l5-swagger:generate`. A green tree makes for honest commits.

## Coding Standards (message quality)

- Subject: capitalized, no trailing period, imperative, concise.
- One concern per commit; no "wip"/"fix stuff" messages in final history.
- Body wrapped, blank line after subject.
- Match the existing project style seen in `git log`.

## Required trailer

End every commit message body with the co-author trailer for AI-assisted commits:

```
Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
```

## Performance Guidelines

- Keep commits small and focused — faster review, cleaner `git bisect`, easier
  reverts.
- Don't bundle a large refactor with a behavior change; separate them.

## Security Considerations

- Diff-scan for secrets before committing: `.env` values, API keys/tokens
  (Stripe/Cashier keys, Sanctum tokens), passwords, certificates. If found,
  unstage and remove them.
- Confirm `.gitignore` covers `.env`, `vendor/`, `node_modules/`, and build
  output; never force-add ignored files.
- If a secret was already committed, tell the user it must be **rotated** (history
  removal alone is insufficient once pushed).

## Common Mistakes

- Committing without the user asking, or without reviewing the diff.
- `git add -A` sweeping in unrelated files (`.env`, debug artifacts, `dd()`
  leftovers).
- Giant, multi-purpose commits that are hard to review or revert.
- Vague subjects ("update", "fix") that hide intent.
- Past-tense or non-imperative subjects.
- Committing failing/unformatted code, or skipping hooks to force it through.
- Forgetting the `Co-Authored-By` trailer.

## Recommended Workflow

1. Confirm the user wants to commit.
2. Run `git status`, `git diff` (and `git diff --staged`), and
   `git log --oneline -5`.
3. Remove debug artifacts (`dd()`, temp logs); run `./vendor/bin/pint --dirty`,
   `./vendor/bin/phpstan analyse`, and `./vendor/bin/pest` for non-trivial
   changes.
4. Stage deliberately with `git add <specific paths>`; scan the staged diff for
   secrets/junk.
5. Write a Conventional Commit message (subject + why-focused body) ending with
   the `Co-Authored-By` trailer. Use a heredoc for multi-line messages.
6. `git commit`; show the user the result (`git log -1 --stat`). Do **not** push
   unless asked.

### Multi-line commit (Bash tool)

```bash
git commit -m "$(cat <<'EOF'
feat(billing): add annual plan swap with proration guard

Lets an org switch to the annual plan mid-cycle. Cashier applies
proration, but we clamp the credit so a downgrade can't zero out the
invoice; the guard is covered by a Pest feature test.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

## Output Expectations

Atomic commit(s) on `main` with Conventional Commit messages that state the why
and end with the required trailer — no secrets, no unrelated files, no
failing/unformatted code. The final commit(s) are shown to the user, and nothing
is pushed unless explicitly requested.
