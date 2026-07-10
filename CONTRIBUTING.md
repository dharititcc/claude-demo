# Contributing

Thanks for your interest in improving this project. This guide keeps contributions consistent and reviewable.

## Getting started

1. Fork and clone the repo.
2. Follow [Local Development](README.md#local-development) to run backend + frontend.
3. Create a branch from `main`: `git checkout -b feat/<short-description>`.

## Branch & commit conventions

- Branches: `feat/…`, `fix/…`, `chore/…`, `docs/…`, `test/…`, `refactor/…`.
- Commits follow [Conventional Commits](https://www.conventionalcommits.org/): `feat(customers): add CSV import`.

## Coding standards

**Backend (Laravel):**

```bash
cd backend
./vendor/bin/pint          # format (fix)
./vendor/bin/pint --test   # check only
./vendor/bin/phpstan analyse
./vendor/bin/pest
```

**Frontend (React):**

```bash
cd frontend
npm run lint
npm run typecheck
npm run test
npm run build
```

All of the above run in CI and must pass before merge.

## Pull requests

- Keep PRs focused and small.
- Include tests for new behavior; maintain the 80% coverage target.
- Update docs/OpenAPI annotations when you change the API.
- Fill in the PR template; link the related issue.

## Reporting bugs / requesting features

Open a GitHub issue using the appropriate template. For security issues, **do not** open a public issue — see [SECURITY.md](SECURITY.md).
