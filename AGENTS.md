# SEO OS Engineering Rules

This repository is the source of truth for SEO OS.

## Read First

Before changing product behavior, read in this order:

1. `PROJECT_STATE.md`
2. `docs/superpowers/specs/2026-10-22-seo-os-design.md`
3. The active implementation plan in `docs/superpowers/plans/`
4. The relevant release-readiness document in `docs/release-readiness/`, when present

The repository state and product spec are authoritative when chat memory or implementation details conflict.

## Continuity Protocol

- Do not rely on conversation history as the primary project state. Inspect GitHub, the active branch, recent CI, `PROJECT_STATE.md`, tests, and release-readiness evidence first.
- Do not redo completed tasks merely because a new chat/agent cannot see the old conversation.
- Update `PROJECT_STATE.md` at meaningful milestones, especially before handing off to another chat/agent.
- When the user says `continue`, keep progressing autonomously until the active bounded task/milestone has fresh verification, or a real external blocker requires user action, or safety rules require stopping.
- Do not stop merely because CI is pending. Poll it to completion when tools are available.
- A formatter, linter, type, static-analysis, test, build, audit, or CI failure is work to diagnose, not a reason to hand the task back to the user.
- Never claim success from stale CI. Record the verified HEAD/run when closing a major milestone.
- If context is near exhaustion, persist the exact continuation point to `PROJECT_STATE.md` before ending whenever possible.

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
