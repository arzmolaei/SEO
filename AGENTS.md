# SEO OS Engineering Rules

This repository is the source of truth for SEO OS.

## Read First

Before changing product behavior, read:

1. `docs/superpowers/specs/2026-10-22-seo-os-design.md`
2. The active implementation plan in `docs/superpowers/plans/`

The product spec is authoritative when implementation details conflict.

## Working Method

- Work on a feature branch or isolated worktree; do not develop directly on `main`.
- Use test-driven development for product behavior: RED → GREEN → refactor.
- A failing check is diagnosed before it is patched. Fix root causes, not symptoms.
- Keep changes scoped to the active task; avoid unrelated refactors.
- Preserve explicit module boundaries and communicate through application services/contracts.
- Never claim a task complete without fresh verification evidence.
- Never commit secrets, `.env`, production credentials, generated private data, or customer data.

## Product Constraints

- PHP 8.3+ is the supported floor.
- Laravel 13 + Inertia 3 + React + TypeScript are the current application foundation.
- Persian is the initial default locale; English and RTL/LTR support are foundational.
- Core runtime must not require an international CDN, AI provider, OAuth provider, or external API.
- Self-host defaults are database queue + local filesystem storage.
- MariaDB 10.11+ is preferred; MySQL 8+ is supported. SQLite is allowed for tests.
- Production releases must not require Node.js or Composer to run after release assets are built.
- Preserve original SEO input data; normalized/derived values live separately.
- Project/workspace boundaries are authorization boundaries.
- Large datasets require server-side filtering/sorting/pagination; do not render entire collections in the browser.
- Bulk/destructive changes must be attributable and reversible where technically safe.

## Required Verification

CI is the source of truth when the local environment cannot install dependencies.

Equivalent local checks are:

```bash
composer install --prefer-dist --no-interaction
cp .env.example .env
php artisan key:generate
php artisan migrate --force
npm ci
php artisan wayfinder:generate --with-form
npm run check
npm run types:check
npm run build
composer lint:check
composer types:check
php artisan test
npm audit --omit=dev --audit-level=high
composer audit --no-dev
```

Do not use `npm audit fix --force` as a shortcut. Investigate dependency advisories and upgrade deliberately.

## Generated Files

Wayfinder action/route files are generated and gitignored. Generate them before TypeScript checks:

```bash
php artisan wayfinder:generate --with-form
```

## Definition of Done

Where applicable, a feature includes behavior, validation, loading/empty/error states, authorization, tests, RTL/LTR correctness, accessibility basics, performance considerations, safe migrations, logs/observability, and documentation.
