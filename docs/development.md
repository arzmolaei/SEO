# Development & Verification

## Supported Foundation

- PHP 8.3+
- Composer 2 for development/build environments
- Node.js 22 for frontend development/build only
- Laravel 13
- Inertia Laravel 3
- React + TypeScript
- MariaDB 10.11+ preferred for production
- MySQL 8+ supported
- SQLite allowed for tests/CI

A normal production release is expected to ship built frontend assets and installed PHP dependencies. The deployed application must not require Node.js as a runtime dependency.

## Local Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm ci
php artisan wayfinder:generate --with-form
npm run build
```

During development, use the normal Laravel/Vite development commands as needed. Do not depend on external CDNs for core application assets.

## Self-host Defaults

The distributed `.env.example` defines the intended self-host baseline:

- `APP_NAME="SEO OS"`
- `APP_LOCALE=fa`
- `APP_FALLBACK_LOCALE=en`
- `QUEUE_CONNECTION=database`
- `FILESYSTEM_DISK=local`

Tests intentionally override the queue to `sync` in `phpunit.xml` for isolation and speed. That test-only override is not the production default.

## Verification Pipeline

Run these before considering a change complete:

```bash
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

GitHub Actions runs the same quality gates on feature branches, `main`, and pull requests.

## Wayfinder

Generated TypeScript actions/routes are intentionally not committed. Generate them before TypeScript checks:

```bash
php artisan wayfinder:generate --with-form
```

## Dependency Security

Runtime dependency audits are release gates. Development-only advisories are investigated deliberately; do not use forced automatic upgrades that can introduce breaking changes.

## Branching

- `main` is the stable integration branch.
- Product work happens on `feature/**` branches or isolated worktrees.
- Do not merge a feature branch until its implementation plan has been completed and whole-branch verification/review has passed.

## Core Product Constraints

- Persian-first but bilingual/RTL-LTR-ready.
- No required AI or international API for core workflows.
- No required external CDN for core assets.
- Self-hosting and migration/backup/update paths are first-class product requirements.
- Workspace/project boundaries are authorization boundaries.
- SEO source data must retain provenance and original values.
